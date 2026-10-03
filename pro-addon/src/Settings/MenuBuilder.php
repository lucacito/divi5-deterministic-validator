<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds the "Main Menu" navigation menu and points a theme location at it, and can put a snapshot of the
 * previous state back. Everything goes through WordPress's own menu functions.
 */
final class MenuBuilder
{
    public const MENU_NAME = 'Main Menu';
    public const MAX_ITEMS = 30;

    /** The theme's primary menu location: the first one named like "primary" or "main", else the first, else ''. */
    public static function targetLocation(): string
    {
        $locations = array_keys( (array) get_registered_nav_menus() );
        foreach ( $locations as $loc ) {
            if ( false !== stripos( (string) $loc, 'primary' ) || false !== stripos( (string) $loc, 'main' ) ) {
                return (string) $loc;
            }
        }

        return [] === $locations ? '' : (string) $locations[0];
    }

    /**
     * Everything needed to put the menu and its location back.
     *
     * @return array{exists:bool, menu_id:int, items:list<array<string,mixed>>, location:string, location_menu_id:int}
     */
    public static function snapshot( string $location ): array
    {
        $mods     = get_theme_mod( 'nav_menu_locations', [] );
        $mods     = is_array( $mods ) ? $mods : [];
        $pointing = '' !== $location ? (int) ( $mods[ $location ] ?? 0 ) : 0;
        $menu     = wp_get_nav_menu_object( self::MENU_NAME );
        if ( ! $menu ) {
            return [ 'exists' => false, 'menu_id' => 0, 'items' => [], 'location' => $location, 'location_menu_id' => $pointing ];
        }

        $raw   = wp_get_nav_menu_items( (int) $menu->term_id );
        $raw   = is_array( $raw ) ? $raw : [];
        $index = [];
        foreach ( $raw as $pos => $o ) {
            $index[ (int) $o->ID ] = $pos;
        }
        $items = [];
        foreach ( $raw as $o ) {
            $type    = (string) $o->type;
            $items[] = [
                'title'       => (string) $o->title,
                'type'        => $type,
                'object'      => (string) $o->object,
                // A custom link's object_id is its own post id in core: a database id, kept out of the snapshot.
                'object_id'   => 'custom' === $type ? 0 : (int) $o->object_id,
                'url'         => 'custom' === $type ? (string) $o->url : '',
                'parent'      => $index[ (int) $o->menu_item_parent ] ?? -1,
                'classes'     => implode( ' ', array_filter( (array) $o->classes ) ),
                'target'      => (string) $o->target,
                'attr_title'  => (string) $o->attr_title,
                'xfn'         => (string) $o->xfn,
                'description' => (string) $o->description,
            ];
        }

        return [ 'exists' => true, 'menu_id' => (int) $menu->term_id, 'items' => $items, 'location' => $location, 'location_menu_id' => $pointing ];
    }

    /**
     * The part of a snapshot that is compared for "changed since": no database ids, so a menu that was deleted
     * and recreated by an undo still compares equal.
     *
     * @param array<string,mixed> $s
     * @return array{exists:bool, items:list<array<string,mixed>>, at_location:bool}
     */
    public static function canonical( array $s ): array
    {
        return [
            'exists'      => (bool) $s['exists'],
            'items'       => $s['items'],
            'at_location' => '' !== (string) $s['location'] && (bool) $s['exists'] && (int) $s['menu_id'] > 0 && (int) $s['location_menu_id'] === (int) $s['menu_id'],
        ];
    }

    /**
     * Rebuild the menu from $items (the snapshot item shape) and point $location at it.
     *
     * @param list<array<string,mixed>> $items
     * @return int the number of items added
     */
    public static function build( array $items, string $location ): int
    {
        $menu   = wp_get_nav_menu_object( self::MENU_NAME );
        $menuId = $menu ? (int) $menu->term_id : wp_create_nav_menu( self::MENU_NAME );
        if ( is_wp_error( $menuId ) ) {
            throw new \RuntimeException( $menuId->get_error_message() );
        }
        self::clearItems( (int) $menuId );
        $added = self::addItems( (int) $menuId, $items );
        self::pointLocation( $location, (int) $menuId );

        return $added;
    }

    /**
     * Put a snapshot back: recreate or empty-and-refill the menu (or delete it when it did not exist) and give the
     * location back to whatever menu it pointed at.
     *
     * @param array<string,mixed> $snap
     */
    public static function restore( array $snap ): void
    {
        $now = wp_get_nav_menu_object( self::MENU_NAME );
        if ( ! $snap['exists'] ) {
            if ( $now ) {
                wp_delete_nav_menu( (int) $now->term_id );
            }
            self::pointLocation( (string) $snap['location'], (int) $snap['location_menu_id'] );

            return;
        }

        $menuId = $now ? (int) $now->term_id : wp_create_nav_menu( self::MENU_NAME );
        if ( is_wp_error( $menuId ) ) {
            throw new \RuntimeException( $menuId->get_error_message() );
        }
        self::clearItems( (int) $menuId );
        $added = self::addItems( (int) $menuId, $snap['items'] );
        if ( $added < count( $snap['items'] ) ) {
            throw new \RuntimeException( 'Only ' . $added . ' of ' . count( $snap['items'] ) . ' menu items could be restored.' );
        }
        $pointed = (int) $snap['location_menu_id'];
        self::pointLocation( (string) $snap['location'], $pointed === (int) $snap['menu_id'] ? (int) $menuId : $pointed );
    }

    private static function clearItems( int $menuId ): void
    {
        foreach ( (array) wp_get_nav_menu_items( $menuId ) as $old ) {
            wp_delete_post( (int) $old->ID, true );
        }
    }

    /** @param list<array<string,mixed>> $items */
    private static function addItems( int $menuId, array $items ): int
    {
        $ids   = [];
        $added = 0;
        foreach ( $items as $i => $it ) {
            $id = wp_update_nav_menu_item( $menuId, 0, [
                'menu-item-title'       => (string) $it['title'],
                'menu-item-status'      => 'publish',
                'menu-item-type'        => (string) $it['type'],
                'menu-item-object'      => (string) $it['object'],
                'menu-item-object-id'   => (int) $it['object_id'],
                'menu-item-url'         => 'custom' === $it['type'] ? (string) $it['url'] : '',
                'menu-item-classes'     => (string) $it['classes'],
                'menu-item-target'      => (string) $it['target'],
                'menu-item-attr-title'  => (string) $it['attr_title'],
                'menu-item-xfn'         => (string) $it['xfn'],
                'menu-item-description' => (string) $it['description'],
                'menu-item-parent-id'   => (int) $it['parent'] >= 0 ? (int) ( $ids[ (int) $it['parent'] ] ?? 0 ) : 0,
            ] );
            if ( is_wp_error( $id ) ) {
                continue;
            }
            $ids[ $i ] = (int) $id;
            $added++;
        }

        return $added;
    }

    /** Point $location at $menuId; 0 clears it. An empty location (the theme has none) does nothing. */
    private static function pointLocation( string $location, int $menuId ): void
    {
        if ( '' === $location ) {
            return;
        }
        $mods = get_theme_mod( 'nav_menu_locations', [] );
        $mods = is_array( $mods ) ? $mods : [];
        if ( $menuId > 0 ) {
            $mods[ $location ] = $menuId;
        } else {
            unset( $mods[ $location ] );
        }
        set_theme_mod( 'nav_menu_locations', $mods );
    }
}
