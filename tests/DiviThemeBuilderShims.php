<?php

declare(strict_types=1);

/**
 * Stand-ins for the few Divi Theme Builder functions the plugin reads, driven by
 *   $GLOBALS['__divi_tb'] = [ 'tb_id' => int, 'draft_id' => int, 'template_ids' => int[], 'draft_template_ids' => int[] ]
 * and recording every call. Required only by tests that need them; guarded like every other shim.
 */
if ( ! function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ) {
    function et_theme_builder_get_theme_builder_post_id( $live, $create = true ) {
        $GLOBALS['__divi_tb_calls'][] = [ 'post_id', (bool) $live, (bool) $create ];

        return (int) ( $GLOBALS['__divi_tb'][ $live ? 'tb_id' : 'draft_id' ] ?? 0 );
    }
}
if ( ! function_exists( 'et_theme_builder_get_theme_builder_template_ids' ) ) {
    function et_theme_builder_get_theme_builder_template_ids( $live, $theme_builder_id = 0 ) {
        $GLOBALS['__divi_tb_calls'][] = [ 'template_ids', (bool) $live, (int) $theme_builder_id ];

        return (array) ( $GLOBALS['__divi_tb'][ $live ? 'template_ids' : 'draft_template_ids' ] ?? [] );
    }
}
if ( ! function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
    function et_theme_builder_clear_wp_cache( $which = 'all' ) {
        $GLOBALS['__divi_cache_clears'][] = $which;
    }
}
if ( ! class_exists( 'ET_Core_PageResource' ) ) {
    class ET_Core_PageResource {
        public static function remove_static_resources( $a, $b ) {
            $GLOBALS['__divi_static_clears'][] = [ $a, $b ];
        }
    }
}
