<?php

declare(strict_types=1);

/**
 * A stand-in for Divi 5.14's global colors API, with the rules of the shipped source
 * (includes/builder-5/server/Packages/GlobalData/GlobalData.php) that matter here:
 *  - get_global_colors() = the five Customizer colors (rebuilt from theme options, CACHED for the whole request in a
 *    static, with a fresh lastUpdated) merged with the stored custom colors; the result is cached until a save;
 *  - set_global_colors($data, false) sanitises (drops entries with an empty color or a non-gcid id, runs
 *    sanitize_text_field over every value, usedInPosts through array_map), writes a Customizer color into its theme
 *    option and removes it from the map, and REPLACES the whole custom map; it does NOT reset the Customizer cache;
 *  - set_global_colors($data, true) would MERGE and skip sanitising (the add-on never calls it that way).
 * State lives in $GLOBALS['__divi_gc'] (see resetForTests); switches: throw_get, throw_set, mutate (callable applied
 * to the sanitised map), on_get (callable called with the get counter before every read).
 * Variables (get_global_variables / set_global_variables) follow the same source file; state lives in $GLOBALS['__divi_gv'].
 */

namespace ET\Builder\Packages\GlobalData {
    class GlobalData
    {
        /** @var array<string,array<string,string>> */
        public static $customizer_colors = [
            'gcid-primary-color'   => [ 'label' => 'Primary Color', 'option_name' => 'accent_color', 'default' => '#2ea3f2' ],
            'gcid-secondary-color' => [ 'label' => 'Secondary Color', 'option_name' => 'secondary_accent_color', 'default' => '#2ea3f2' ],
            'gcid-heading-color'   => [ 'label' => 'Heading Text Color', 'option_name' => 'header_color', 'default' => '#666666' ],
            'gcid-body-color'      => [ 'label' => 'Body Text Color', 'option_name' => 'font_color', 'default' => '#666666' ],
            'gcid-link-color'      => [ 'label' => 'Link Color', 'option_name' => 'link_color', 'default' => '#2ea3f2' ],
        ];

        /**
         * @param array<string,array<string,mixed>> $custom stored custom colors
         * @param array<string,string> $options theme options by name (accent_color, ...)
         */
        public static function resetForTests( array $custom = [], array $options = [] ): void
        {
            $GLOBALS['__divi_gc'] = [
                'custom' => $custom, 'options' => $options, 'cache' => null, 'formatted' => null, 'clock' => 0,
                'set_calls' => [], 'set_flags' => [], 'gets' => 0,
                'throw_get' => false, 'throw_set' => false, 'mutate' => null, 'on_get' => null,
            ];
            $GLOBALS['__divi_static_clears'] = [];
        }

        /** @return array<string,array<string,mixed>> */
        public static function get_global_colors(): array
        {
            if ( ! isset( $GLOBALS['__divi_gc'] ) ) {
                self::resetForTests(); // other suites load this file too (PHPUnit shares one process): never read an unset state
            }
            ++$GLOBALS['__divi_gc']['gets'];
            if ( is_callable( $GLOBALS['__divi_gc']['on_get'] ) ) {
                ( $GLOBALS['__divi_gc']['on_get'] )( $GLOBALS['__divi_gc']['gets'] );
            }
            if ( $GLOBALS['__divi_gc']['throw_get'] ) {
                throw new \RuntimeException( 'Divi could not read the colors' );
            }
            if ( null === $GLOBALS['__divi_gc']['cache'] ) {
                $custom = $GLOBALS['__divi_gc']['custom'];
                foreach ( array_keys( self::$customizer_colors ) as $id ) {
                    unset( $custom[ $id ] );
                }
                $GLOBALS['__divi_gc']['cache'] = array_merge( self::get_customizer_colors(), $custom );
            }

            return $GLOBALS['__divi_gc']['cache'];
        }

        /** @return array<string,array<string,mixed>> */
        public static function get_customizer_colors(): array
        {
            if ( ! isset( $GLOBALS['__divi_gc'] ) ) {
                self::resetForTests();
            }
            if ( null !== $GLOBALS['__divi_gc']['formatted'] ) {
                return $GLOBALS['__divi_gc']['formatted'];
            }
            $out = [];
            foreach ( self::$customizer_colors as $id => $def ) {
                $out[ $id ] = [
                    'color'       => sanitize_text_field( (string) et_get_option( $def['option_name'], $def['default'] ) ),
                    'folder'      => 'customizer',
                    'label'       => $def['label'],
                    'lastUpdated' => '2026-02-01T00:00:00.' . sprintf( '%03d', ++$GLOBALS['__divi_gc']['clock'] ) . 'Z',
                    'status'      => 'active',
                    'usedInPosts' => [],
                ];
            }

            return $GLOBALS['__divi_gc']['formatted'] = $out;
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,array<string,mixed>>
         */
        public static function sanitize_global_colors_data( array $data ): array
        {
            $out = [];
            foreach ( $data as $id => $item ) {
                if ( 'undefined' === $id || empty( $item ) || empty( $item['color'] ) || 'gcid-' !== substr( (string) $id, 0, 5 ) ) {
                    continue;
                }
                $gid = sanitize_text_field( (string) $id );
                foreach ( $item as $k => $v ) {
                    $out[ $gid ][ sanitize_text_field( (string) $k ) ] = 'usedInPosts' === $k ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( $v );
                }
            }

            return $out;
        }

        /** @param array<string,mixed> $data */
        public static function set_global_colors( array $data, $already_sanitized = false ): void
        {
            if ( ! isset( $GLOBALS['__divi_gc'] ) ) {
                self::resetForTests();
            }
            $s = &$GLOBALS['__divi_gc'];
            $s['set_calls'][] = $data;
            $s['set_flags'][] = $already_sanitized;
            if ( $s['throw_set'] ) {
                throw new \RuntimeException( 'Divi refused the save' );
            }
            $s['cache'] = null; // NOT 'formatted': Divi's Customizer cache survives a save, within the request
            if ( ! $already_sanitized ) {
                $data = self::sanitize_global_colors_data( $data );
            }
            if ( is_callable( $s['mutate'] ) ) {
                $data = ( $s['mutate'] )( $data );
            }
            foreach ( self::$customizer_colors as $id => $def ) {
                if ( isset( $data[ $id ]['color'] ) ) {
                    et_update_option( $def['option_name'], $data[ $id ]['color'] );
                }
                unset( $data[ $id ] );
            }
            $s['custom'] = $data; // replace all
            \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
        }

        /** @var array<string,array<string,mixed>> Divi's two built-in font variables (their ids do NOT start with gvid-). */
        public static $customizer_fonts = [
            '--et_global_heading_font' => [ 'label' => 'Heading', 'option_name' => 'heading_font', 'default' => 'Open Sans' ],
            '--et_global_body_font'    => [ 'label' => 'Body', 'option_name' => 'body_font', 'default' => 'Open Sans' ],
        ];

        /** @param array<string,array<string,array<string,mixed>>> $stored the groups as stored (type => id => entry) */
        public static function resetVariablesForTests( array $stored = [] ): void
        {
            $GLOBALS['__divi_gv'] = [
                'stored' => $stored, 'cache' => null, 'gets' => 0, 'set_calls' => [],
                'throw_get' => false, 'throw_set' => false, 'mutate' => null, 'on_get' => null,
            ];
        }

        /** Divi's read: seven groups as objects, allowedActions on every entry, the customizer fonts merged into `fonts`; cached until a save. */
        public static function get_global_variables(): array
        {
            if ( ! isset( $GLOBALS['__divi_gv'] ) ) {
                self::resetVariablesForTests();
            }
            $s = &$GLOBALS['__divi_gv'];
            ++$s['gets'];
            if ( is_callable( $s['on_get'] ) ) {
                ( $s['on_get'] )( $s['gets'] );
            }
            if ( $s['throw_get'] ) {
                throw new \RuntimeException( 'Divi could not read the variables' );
            }
            if ( null === $s['cache'] ) {
                $all   = array_merge( [ 'numbers' => [], 'strings' => [], 'images' => [], 'links' => [], 'colors' => [], 'fonts' => [], 'gradients' => [] ], $s['stored'] );
                $fonts = is_array( $all['fonts'] ) ? $all['fonts'] : [];
                $own   = [];
                foreach ( self::$customizer_fonts as $fid => $def ) {
                    unset( $fonts[ $fid ] );
                    $own[ $fid ] = [ 'id' => $fid, 'label' => $def['label'], 'value' => $def['default'], 'status' => 'active', 'order' => '--et_global_heading_font' === $fid ? 1 : 2 ];
                }
                $all['fonts'] = array_merge( $own, $fonts );
                foreach ( $all as $type => $items ) {
                    if ( ! is_array( $items ) ) {
                        $all[ $type ] = [];
                        continue;
                    }
                    foreach ( $items as $id => $item ) {
                        $all[ $type ][ $id ] = array_merge( $item, [ 'allowedActions' => [ 'editLabel' => true, 'editValue' => true, 'reorder' => true, 'remove' => true ] ] );
                    }
                }
                $s['cache'] = array_map( static fn ( $items ) => (object) $items, $all );
            }

            return $s['cache'];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,array<string,array<string,mixed>>>
         */
        public static function sanitize_global_variables_data( array $data ): array
        {
            $out = [];
            foreach ( $data as $type => $items ) {
                if ( ! in_array( $type, [ 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ], true ) || empty( $items ) ) {
                    continue;
                }
                foreach ( $items as $id => $item ) {
                    if ( 'undefined' === $id || empty( $item ) || empty( $item['label'] ) || 'gvid-' !== substr( (string) $id, 0, 5 ) ) {
                        continue;
                    }
                    $gid = sanitize_text_field( (string) $id );
                    foreach ( $item as $k => $v ) {
                        if ( 'allowedActions' === $k ) {
                            continue;
                        }
                        $out[ $type ][ $gid ][ sanitize_text_field( (string) $k ) ] = in_array( $type, [ 'strings', 'gradients' ], true ) && 'value' === $k
                            ? ( is_scalar( $v ) ? (string) $v : '' )
                            : sanitize_text_field( $v );
                    }
                }
            }

            return $out;
        }

        /** @param array<string,mixed> $data */
        public static function set_global_variables( array $data ): void
        {
            if ( ! isset( $GLOBALS['__divi_gv'] ) ) {
                self::resetVariablesForTests();
            }
            $s = &$GLOBALS['__divi_gv'];
            $s['set_calls'][] = $data;
            if ( $s['throw_set'] ) {
                throw new \RuntimeException( 'Divi refused the save' );
            }
            if ( ! current_user_can( 'edit_theme_options' ) || ! et_pb_is_allowed( 'variables_manager' ) ) {
                return; // Divi's own SILENT no-op without the permission
            }
            $s['cache'] = null;
            $data       = self::sanitize_global_variables_data( $data );
            if ( is_callable( $s['mutate'] ) ) {
                $data = ( $s['mutate'] )( $data );
            }
            $s['stored'] = $data; // replace all
            \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
        }
    }

    if ( ! class_exists( GlobalPreset::class ) ) {
        /**
         * A stand-in for Divi 5.14's preset storage (state in $GLOBALS['__divi_gp']): get_data() returns the stored data;
         * save_data() REPLACES it; process_presets_for_import() is ADDITIVE: an item whose id already exists with the same
         * name and attrs is skipped, with other content it is stored as a copy under a NEW id, and a module with no presets
         * gets a default preset created by Divi. Divi's PHP functions check no capability.
         */
        class GlobalPreset
        {
            /** @param array<string,mixed> $data */
            public static function resetForTests( array $data = [] ): void
            {
                $GLOBALS['__divi_gp'] = [
                    'data' => $data, 'save_calls' => [], 'import_calls' => [], 'gets' => 0,
                    'throw_get' => false, 'throw_save' => false, 'throw_import' => false, 'mutate' => null, 'on_get' => null, 'now' => 1790000000,
                ];
            }

            public static function option_name(): string
            {
                return 'builder_global_presets_d5';
            }

            /** @return array<string,mixed> */
            public static function get_data(): array
            {
                if ( ! isset( $GLOBALS['__divi_gp'] ) ) {
                    self::resetForTests();
                }
                $s = &$GLOBALS['__divi_gp'];
                ++$s['gets'];
                if ( is_callable( $s['on_get'] ) ) {
                    ( $s['on_get'] )( $s['gets'] );
                }
                if ( $s['throw_get'] ) {
                    throw new \RuntimeException( 'Divi could not read the presets' );
                }

                return $s['data'];
            }

            /**
             * @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            public static function save_data( array $data, bool $skip_external_cache = false ): array
            {
                if ( ! isset( $GLOBALS['__divi_gp'] ) ) {
                    self::resetForTests();
                }
                $s = &$GLOBALS['__divi_gp'];
                $s['save_calls'][] = $data;
                if ( $s['throw_save'] ) {
                    throw new \RuntimeException( 'Divi refused the save' );
                }
                if ( is_callable( $s['mutate'] ) ) {
                    $data = ( $s['mutate'] )( $data );
                }
                $s['data'] = $data;
                \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );

                return $s['data'];
            }

            /**
             * @param array<string,mixed> $presets
             * @return array<string,mixed>
             */
            public static function process_presets_for_import( array $presets, bool $auto_save = true, bool $skip_external_cache = false ): array
            {
                if ( ! isset( $GLOBALS['__divi_gp'] ) ) {
                    self::resetForTests();
                }
                $s = &$GLOBALS['__divi_gp'];
                $s['import_calls'][] = $presets;
                if ( $s['throw_import'] ) {
                    throw new \RuntimeException( 'Divi refused the import' );
                }
                $data     = $s['data'];
                $mappings = [ 'module' => [], 'group' => [] ];
                foreach ( (array) ( $presets['module'] ?? [] ) as $module => $entry ) {
                    $existed = [] !== (array) ( $data['module'][ $module ]['items'] ?? [] );
                    if ( ! isset( $data['module'][ $module ] ) ) {
                        $data['module'][ $module ] = [ 'default' => '', 'items' => [] ];
                    }
                    foreach ( (array) ( $entry['items'] ?? [] ) as $id => $item ) {
                        $exists = $data['module'][ $module ]['items'][ $id ] ?? null;
                        if ( null !== $exists ) {
                            if ( ( $exists['name'] ?? null ) === ( $item['name'] ?? null ) && ( $exists['attrs'] ?? null ) === ( $item['attrs'] ?? null ) ) {
                                continue;
                            }
                            $new                     = wp_generate_uuid4();
                            $mappings['module'][ $id ] = $new;
                            $item['id']              = $new;
                            $id                      = $new;
                        }
                        $item['created']                              = $s['now'];
                        $item['updated']                              = $s['now'];
                        $data['module'][ $module ]['items'][ $id ] = $item;
                    }
                    if ( ! $existed ) {
                        $did                                       = wp_generate_uuid4();
                        $data['module'][ $module ]['items'][ $did ] = [ 'id' => $did, 'moduleName' => $module, 'name' => ucfirst( substr( (string) $module, 5 ) ) . ' 1', 'attrs' => [], 'styleAttrs' => [], 'version' => '5.14.0', 'type' => 'module', 'created' => $s['now'], 'updated' => $s['now'] ];
                        $data['module'][ $module ]['default']      = $did;
                    }
                }
                if ( $auto_save ) {
                    if ( is_callable( $s['mutate'] ) ) {
                        $data = ( $s['mutate'] )( $data );
                    }
                    $s['data'] = $data;
                    \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
                }

                return [ 'presets' => $data, 'preset_id_mappings' => $mappings, 'defaultImportedModulePresetIds' => [], 'defaultImportedGroupPresetIds' => [] ];
            }
        }
    }
}

namespace {
    if ( ! class_exists( 'ET_Core_PageResource' ) ) {
        class ET_Core_PageResource
        {
            public static function remove_static_resources( $a, $b, $c = false ) { $GLOBALS['__divi_static_clears'][] = [ $a, $b ]; }
        }
    }
    if ( ! function_exists( 'et_get_option' ) ) {
        function et_get_option( $name, $default = false ) { return $GLOBALS['__divi_gc']['options'][ $name ] ?? $default; }
    }
    if ( ! function_exists( 'et_update_option' ) ) {
        function et_update_option( $name, $value ) { $GLOBALS['__divi_gc']['options'][ $name ] = $value; }
    }
    if ( ! function_exists( 'et_pb_is_allowed' ) ) {
        function et_pb_is_allowed( $capabilities, $role = '' ) { return $GLOBALS['__divi_role_allowed'] ?? true; }
    }
    if ( ! function_exists( 'wp_generate_uuid4' ) ) {
        function wp_generate_uuid4() { return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( random_bytes( 16 ) ), 4 ) ); }
    }
}
