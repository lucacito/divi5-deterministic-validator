<?php

declare(strict_types=1);

/**
 * In-memory WordPress settings shims for the Pro tools: Additional CSS now, menus and post status/permalink
 * helpers in later tasks. Every definition is guarded so it never clashes with another shim.
 */

$GLOBALS['__wp_custom_css']      = '';
$GLOBALS['__wp_custom_css_fail'] = false;

if ( ! function_exists( 'wp_get_custom_css' ) ) {
    function wp_get_custom_css( $stylesheet = '' ) { return (string) $GLOBALS['__wp_custom_css']; }
}
if ( ! function_exists( 'wp_update_custom_css_post' ) ) {
    function wp_update_custom_css_post( $css, $args = [] ) {
        if ( $GLOBALS['__wp_custom_css_fail'] ) {
            return new WP_Error( 'css_failed', 'simulated css failure' );
        }
        $GLOBALS['__wp_custom_css'] = (string) $css;

        return (object) [ 'ID' => 900, 'post_content' => (string) $css ];
    }
}

// ---- Navigation menus, theme mods --------------------------------------------------------
$GLOBALS['__wp_menus']            = [];                                   // term_id => name
$GLOBALS['__wp_menu_items']       = [];                                   // menu_id => [ item_id => object ]
$GLOBALS['__wp_menu_seq']         = 100;
$GLOBALS['__wp_menu_item_fail_at'] = 0;                                   // 1-based call number of wp_update_nav_menu_item that fails; 0 = never
$GLOBALS['__wp_menu_item_calls']   = 0;
$GLOBALS['__wp_theme_mods']       = [];
$GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
    function wp_get_nav_menu_object( $name ) {
        foreach ( $GLOBALS['__wp_menus'] as $id => $n ) {
            if ( $n === $name ) {
                return (object) [ 'term_id' => $id, 'name' => $n ];
            }
        }

        return false;
    }
}
if ( ! function_exists( 'wp_create_nav_menu' ) ) {
    function wp_create_nav_menu( $name ) {
        if ( false !== wp_get_nav_menu_object( $name ) ) {
            return new WP_Error( 'menu_exists', 'The menu name already exists.' );
        }
        $id                                = ++$GLOBALS['__wp_menu_seq'];
        $GLOBALS['__wp_menus'][ $id ]      = $name;
        $GLOBALS['__wp_menu_items'][ $id ] = [];

        return $id;
    }
}
if ( ! function_exists( 'wp_delete_nav_menu' ) ) {
    function wp_delete_nav_menu( $id ) {
        unset( $GLOBALS['__wp_menus'][ (int) $id ], $GLOBALS['__wp_menu_items'][ (int) $id ] );
        $mods = $GLOBALS['__wp_theme_mods']['nav_menu_locations'] ?? [];
        foreach ( $mods as $loc => $menu ) {
            if ( (int) $menu === (int) $id ) {
                unset( $mods[ $loc ] );
            }
        }
        $GLOBALS['__wp_theme_mods']['nav_menu_locations'] = $mods;

        return true;
    }
}
if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
    function wp_get_nav_menu_items( $menuId ) {
        $items = array_values( $GLOBALS['__wp_menu_items'][ (int) $menuId ] ?? [] );
        usort( $items, static fn ( $a, $b ) => $a->menu_order <=> $b->menu_order );

        return $items;
    }
}
if ( ! function_exists( 'wp_get_nav_menus' ) ) {
    function wp_get_nav_menus( $args = [] ) {
        $out = [];
        foreach ( $GLOBALS['__wp_menus'] as $id => $name ) {
            $out[] = (object) [ 'term_id' => $id, 'name' => $name ];
        }

        return $out;
    }
}
if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
    function wp_update_nav_menu_item( $menuId, $itemId = 0, $args = [] ) {
        if ( $GLOBALS['__wp_menu_item_fail_at'] > 0 && ++$GLOBALS['__wp_menu_item_calls'] === $GLOBALS['__wp_menu_item_fail_at'] ) {
            return new WP_Error( 'item_failed', 'simulated menu item failure' );
        }
        $id   = ++$GLOBALS['__wp_menu_seq'];
        $type = $args['menu-item-type'] ?? 'custom';
        $oid  = (int) ( $args['menu-item-object-id'] ?? 0 );
        $GLOBALS['__wp_menu_items'][ (int) $menuId ][ $id ] = (object) [
            'ID'               => $id,
            'title'            => $args['menu-item-title'] ?? '',
            'type'             => $type,
            'object'           => $args['menu-item-object'] ?? 'custom',
            'object_id'        => 'custom' === $type ? $id : $oid, // like core: a custom item's object id is its own post id
            'url'              => 'custom' === $type ? (string) ( $args['menu-item-url'] ?? '' ) : 'https://example.com/?p=' . $oid,
            'menu_item_parent' => (int) ( $args['menu-item-parent-id'] ?? 0 ),
            'menu_order'       => $id,
            'classes'          => array_values( array_filter( explode( ' ', (string) ( $args['menu-item-classes'] ?? '' ) ) ) ),
            'target'           => (string) ( $args['menu-item-target'] ?? '' ),
            'attr_title'       => (string) ( $args['menu-item-attr-title'] ?? '' ),
            'xfn'              => (string) ( $args['menu-item-xfn'] ?? '' ),
            'description'      => (string) ( $args['menu-item-description'] ?? '' ),
        ];

        return $id;
    }
}
if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $id, $force = false ) {
        foreach ( array_keys( $GLOBALS['__wp_menu_items'] ) as $menu ) {
            unset( $GLOBALS['__wp_menu_items'][ $menu ][ (int) $id ] );
        }
        unset( $GLOBALS['__wp_posts'][ (int) $id ] );

        return true;
    }
}
if ( ! function_exists( 'get_registered_nav_menus' ) ) {
    function get_registered_nav_menus() { return $GLOBALS['__wp_registered_menus']; }
}
if ( ! function_exists( 'get_theme_mod' ) ) {
    function get_theme_mod( $name, $default = false ) { return $GLOBALS['__wp_theme_mods'][ $name ] ?? $default; }
}
if ( ! function_exists( 'set_theme_mod' ) ) {
    function set_theme_mod( $name, $value ) { $GLOBALS['__wp_theme_mods'][ $name ] = $value; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        $url = trim( (string) $url );

        return 1 === preg_match( '#^(https?://|/|\#)#i', $url ) ? $url : '';
    }
}

// ---- Posts ---------------------------------------------------------------------------------
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $post = 0 ) { return 'https://example.com/?p=' . ( is_object( $post ) ? $post->ID : (int) $post ); }
}
if ( ! function_exists( 'get_date_from_gmt' ) ) {
    function get_date_from_gmt( $gmt, $format = 'Y-m-d H:i:s' ) { return (string) $gmt; }
}
