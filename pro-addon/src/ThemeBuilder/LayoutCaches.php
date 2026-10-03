<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * After a header, footer or template changes: clear Divi's own caches the way Divi's Theme Builder does when it saves
 * (a layout is used by many pages). Each call is guarded, so nothing happens when Divi, or that part of it, is missing.
 */
final class LayoutCaches
{
    public static function clear(): void
    {
        if ( function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
            et_theme_builder_clear_wp_cache( 'all' );
        }
        if ( class_exists( 'ET_Core_PageResource' ) ) {
            \ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        }
    }
}
