<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds the site's primary navigation menu from a list of items and assigns it
 * to a primary theme menu location. Idempotent — rebuilds the "Main Menu" each
 * call. Shared by the set_primary_menu MCP tool and POST /primary-menu.
 */
final class MenuBuilder
{
    private const MENU_NAME = 'Main Menu';

    /**
     * @param array<int, array{title?:string, page_id?:int, url?:string}> $items
     * @return array{menu_id:int, items:int, location:string}
     */
    public static function build(array $items): array
    {
        $existing = wp_get_nav_menu_object(self::MENU_NAME);
        $menuId   = $existing ? (int) $existing->term_id : wp_create_nav_menu(self::MENU_NAME);
        if (is_wp_error($menuId)) {
            return ['menu_id' => 0, 'items' => 0, 'location' => 'error: ' . $menuId->get_error_message()];
        }

        // Rebuild from scratch for idempotency.
        foreach ((array) wp_get_nav_menu_items($menuId) as $old) {
            wp_delete_post((int) $old->ID, true);
        }

        $added = 0;
        foreach ($items as $item) {
            $title = sanitize_text_field((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $itemArgs = ['menu-item-title' => $title, 'menu-item-status' => 'publish'];
            if (!empty($item['page_id'])) {
                $itemArgs['menu-item-type']      = 'post_type';
                $itemArgs['menu-item-object']    = 'page';
                $itemArgs['menu-item-object-id'] = (int) $item['page_id'];
            } else {
                $itemArgs['menu-item-type'] = 'custom';
                $itemArgs['menu-item-url']  = esc_url_raw((string) ($item['url'] ?? '#'));
            }
            if (!is_wp_error(wp_update_nav_menu_item($menuId, 0, $itemArgs))) {
                $added++;
            }
        }

        // Assign to a primary-ish theme location, or the first registered one.
        $locations = get_registered_nav_menus();
        $assigned  = get_theme_mod('nav_menu_locations', []);
        $target    = null;
        foreach (array_keys($locations) as $loc) {
            if (stripos($loc, 'primary') !== false || stripos($loc, 'main') !== false) {
                $target = $loc;
                break;
            }
        }
        if ($target === null && $locations !== []) {
            $target = (string) array_key_first($locations);
        }
        if ($target !== null) {
            $assigned[$target] = $menuId;
            set_theme_mod('nav_menu_locations', $assigned);
        }

        return [
            'menu_id'  => (int) $menuId,
            'items'    => $added,
            'location' => $target ?? '(no theme menu location found — assign "Main Menu" manually)',
        ];
    }
}
