<?php

declare(strict_types=1);

/**
 * Stand-ins for the three Divi 5 read functions behind GlobalStyles, driven by
 *   $GLOBALS['__divi_presets']   the presets data  ({module: {<name>: {default, items}}}), or null = the method throws
 *   $GLOBALS['__divi_colors']    the global colors (id => {color, label, status, folder}), or null = the method throws
 *   $GLOBALS['__divi_variables'] the global variables (type => {id => {...}}), or null = the method throws
 * Required only by tests that need them; guarded like every other shim.
 */

namespace ET\Builder\Packages\GlobalData {
    if ( ! class_exists( GlobalPreset::class ) ) {
        class GlobalPreset {
            public static function get_data(): array {
                if ( ! array_key_exists( '__divi_presets', $GLOBALS ) ) {
                    return [];
                }
                if ( null === $GLOBALS['__divi_presets'] ) {
                    throw new \RuntimeException( 'presets unavailable' );
                }

                return $GLOBALS['__divi_presets'];
            }
        }
    }
    if ( ! class_exists( GlobalData::class ) ) {
        class GlobalData {
            public static function get_global_colors(): array {
                if ( ! array_key_exists( '__divi_colors', $GLOBALS ) ) {
                    return [];
                }
                if ( null === $GLOBALS['__divi_colors'] ) {
                    throw new \RuntimeException( 'colors unavailable' );
                }

                return $GLOBALS['__divi_colors'];
            }

            public static function get_global_variables(): array {
                if ( ! array_key_exists( '__divi_variables', $GLOBALS ) ) {
                    return [];
                }
                if ( null === $GLOBALS['__divi_variables'] ) {
                    throw new \RuntimeException( 'variables unavailable' );
                }

                return $GLOBALS['__divi_variables'];
            }
        }
    }
}
