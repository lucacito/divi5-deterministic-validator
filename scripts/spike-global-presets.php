<?php
/**
 * ONE-OFF evidence spike for a future Pro "manage_global_presets" tool: can a module preset be ADDED through Divi's own
 * GlobalPreset::process_presets_for_import() (additive merge), and does a layout that cites the new id render with the preset's styling?
 * It snapshots the stored presets option, adds ONE throwaway heading preset (a font size of 57px and a bottom margin of 39px) built from
 * the shape of a real stored preset, renders throwaway published pages (cited / not cited / unknown id), tries the same import twice,
 * removes the preset again by saving the current data without it, and finally restores the snapshot byte for byte.
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-global-presets.php --user=1
 * Dev sites only (scripts/dev-guard.php). Set AIED_LOOPBACK_BASE if http://wordpress is not reachable from the wpcli container.
 */

use ET\Builder\Packages\GlobalData\GlobalPreset;

if ( ! class_exists( GlobalPreset::class ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalPreset class is not loaded: activate the Divi theme on this site first." );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

global $wpdb;
$optionName = GlobalPreset::option_name();
$raw        = static fn () => maybe_unserialize( et_get_option( $optionName, [], '', true, false, '', '', true ) );
$snapshot   = $raw();
$snapshotS  = serialize( $snapshot );

$newRequest = static function (): void {
    if ( class_exists( 'ET_Core_PageResource' ) && property_exists( 'ET_Core_PageResource', '_global_timestamp_updated' ) ) {
        $prop = new ReflectionProperty( 'ET_Core_PageResource', '_global_timestamp_updated' );
        $prop->setAccessible( true );
        $prop->setValue( null, false );
    }
};
$fetch = static function ( string $url ) use ( &$fetch ): string {
    $p    = wp_parse_url( $url );
    $host = ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    $base = rtrim( (string) ( getenv( 'AIED_LOOPBACK_BASE' ) ?: 'http://wordpress' ), '/' );
    $res  = wp_remote_get( $base . $path, [ 'headers' => [ 'Host' => $host ], 'timeout' => 30, 'redirection' => 0, 'sslverify' => false ] );
    if ( is_wp_error( $res ) ) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( in_array( $code, [ 301, 302 ], true ) && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) ) {
        return $fetch( (string) wp_remote_retrieve_header( $res, 'location' ) );
    }

    return (string) wp_remote_retrieve_body( $res );
};
$render = static function ( int $pageId ) use ( $fetch ): string {
    $html = $fetch( add_query_arg( 'page_id', $pageId, home_url( '/' ) ) );
    $all  = $html;
    if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+\.css[^\'"]*)[\'"]/i', $html, $m ) ) {
        $site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( array_slice( array_unique( $m[1] ), 0, 25 ) as $href ) {
            if ( $site === (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
                $all .= "\n" . $fetch( $href );
            }
        }
    }

    return $all;
};
$context = static function ( string $haystack, string $needle, int $max = 3 ): array {
    $out = [];
    $off = 0;
    while ( count( $out ) < $max && false !== ( $pos = strpos( $haystack, $needle, $off ) ) ) {
        $out[] = substr( $haystack, max( 0, $pos - 120 ), strlen( $needle ) + 240 );
        $off   = $pos + strlen( $needle );
    }

    return $out;
};
/** Up to $max paths at which two nested arrays differ. */
$diffPaths = static function ( $a, $b, string $prefix = '' ) use ( &$diffPaths ): array {
    if ( ! is_array( $a ) || ! is_array( $b ) ) {
        return $a === $b ? [] : [ $prefix ];
    }
    $out = [];
    foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
        if ( ! array_key_exists( $k, $a ) || ! array_key_exists( $k, $b ) ) {
            $out[] = $prefix . '/' . $k . ( array_key_exists( $k, $a ) ? ' (removed)' : ' (added)' );
        } else {
            array_push( $out, ...$diffPaths( $a[ $k ], $b[ $k ], $prefix . '/' . $k ) );
        }
        if ( count( $out ) > 30 ) {
            break;
        }
    }

    return array_slice( $out, 0, 30 );
};
$layout = static function ( array $headings ): string {
    $blocks = '';
    foreach ( $headings as [ $text, $preset ] ) {
        $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ], 'builderVersion' => '5.14.0' ];
        if ( null !== $preset ) {
            $attrs = [ 'modulePreset' => [ $preset ] ] + $attrs;
        }
        $blocks .= '<!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /-->';
    }

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->' . $blocks . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};

$out   = [ 'option_name' => $optionName ];
$pages = [];
try {
    // 1. What is stored.
    $before = GlobalPreset::get_data();
    $out['before_counts']      = GlobalPreset::count_presets( $before );
    $out['before_top_keys']    = array_keys( $before );
    $out['heading_default_id'] = $before['module']['divi/heading']['default'] ?? null;
    $tplId                     = '';
    foreach ( (array) ( $before['module']['divi/heading']['items'] ?? [] ) as $id => $item ) {
        if ( $id !== $out['heading_default_id'] ) {
            $tplId = (string) $id;
            break;
        }
    }
    $tpl = $before['module']['divi/heading']['items'][ $tplId ] ?? null;
    if ( ! is_array( $tpl ) ) {
        throw new RuntimeException( 'no non-default heading preset to use as a shape template' );
    }
    $out['template_item_keys']  = array_keys( $tpl );
    $out['template_item_meta']  = array_diff_key( $tpl, array_flip( [ 'attrs', 'styleAttrs', 'renderAttrs' ] ) );
    $out['module_entry_keys']   = array_keys( $before['module']['divi/heading'] );

    // 2. A throwaway preset, same shape as the real one.
    $newId = wp_generate_uuid4();
    $style = [
        'title'  => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '57px' ] ] ] ] ] ],
        'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'top' => '', 'right' => '', 'bottom' => '39px', 'left' => '', 'syncVertical' => 'off', 'syncHorizontal' => 'off' ] ] ] ] ] ],
    ];
    $item = array_merge( $tpl, [ 'id' => $newId, 'name' => 'AIED probe heading', 'attrs' => $style, 'styleAttrs' => $style ] );
    unset( $item['renderAttrs'] );
    $input = [ 'module' => [ 'divi/heading' => [ 'default' => $out['heading_default_id'], 'items' => [ $newId => $item ] ] ] ];
    $out['import_input_item_keys'] = array_keys( $item );

    // 3. The additive import.
    $newRequest();
    $result = GlobalPreset::process_presets_for_import( $input, true );
    $out['import_result_keys']       = array_keys( $result );
    $out['preset_id_mappings']       = $result['preset_id_mappings'] ?? null;
    $out['defaultImportedModuleIds'] = $result['defaultImportedModulePresetIds'] ?? null;
    $after = GlobalPreset::get_data();
    $mapped = $result['preset_id_mappings'][ $newId ] ?? $newId;
    $out['mapped_id_differs']        = $mapped !== $newId;
    $out['after_counts']             = GlobalPreset::count_presets( $after );
    $out['new_item_stored']          = isset( $after['module']['divi/heading']['items'][ $mapped ] );
    $out['stored_new_item_meta']     = isset( $after['module']['divi/heading']['items'][ $mapped ] ) ? array_diff_key( $after['module']['divi/heading']['items'][ $mapped ], array_flip( [ 'attrs', 'styleAttrs' ] ) ) : null;
    $afterNoNew                      = $after;
    unset( $afterNoNew['module']['divi/heading']['items'][ $mapped ] );
    $out['everything_else_unchanged'] = $diffPaths( $before, $afterNoNew );
    $out['heading_default_after']    = $after['module']['divi/heading']['default'] ?? null;

    // 4. Render: cited / not cited / unknown id.
    $unknown = wp_generate_uuid4();
    $content = $layout( [ [ 'AIEDPRESETPROBE A cited', $mapped ], [ 'AIEDPRESETPROBE B plain', null ], [ 'AIEDPRESETPROBE C unknown', $unknown ] ] );
    $pageId  = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied preset spike', 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $pageId ) ) {
        throw new RuntimeException( $pageId->get_error_message() );
    }
    $pages[] = (int) $pageId;
    $newRequest();
    $css = $render( (int) $pageId );
    $out['render'] = [
        'bytes'                  => strlen( $css ),
        'served_page_id'         => preg_match( '/<body[^>]*\bpage-id-(\d+)/', $css, $mp ) ? (int) $mp[1] : null,
        'probe_page_id'          => (int) $pageId,
        'size_57px_contexts'     => $context( $css, '57px' ),
        'margin_39px_contexts'   => $context( $css, '39px' ),
        'preset_id_in_page'      => $context( $css, $mapped, 3 ),
        'unknown_id_in_page'     => $context( $css, $unknown, 2 ),
        'headings_html'          => $context( $css, 'AIEDPRESETPROBE', 3 ),
    ];

    // 5. The same import again: duplicate handling.
    $newRequest();
    $again = GlobalPreset::process_presets_for_import( $input, true );
    $afterTwice = GlobalPreset::get_data();
    $out['second_import'] = [
        'counts'             => GlobalPreset::count_presets( $afterTwice ),
        'id_mappings'        => $again['preset_id_mappings'] ?? null,
        'same_data_as_after' => $afterTwice === $after,
        'diff_vs_after'      => $diffPaths( $after, $afterTwice ),
    ];

    // 6. Removal by saving the current data without the preset(s) this probe added.
    $current = GlobalPreset::get_data();
    foreach ( array_diff( array_keys( (array) ( $current['module']['divi/heading']['items'] ?? [] ) ), array_keys( (array) ( $before['module']['divi/heading']['items'] ?? [] ) ) ) as $added ) {
        unset( $current['module']['divi/heading']['items'][ $added ] );
    }
    GlobalPreset::save_data( $current );
    $out['removal'] = [
        'counts_after_removal' => GlobalPreset::count_presets( GlobalPreset::get_data() ),
        'data_equals_before'   => GlobalPreset::get_data() === $before,
        'diff_vs_before'       => $diffPaths( $before, GlobalPreset::get_data() ),
        'raw_equals_snapshot'  => serialize( $raw() ) === $snapshotS,
    ];
    // ---- Round 2 -----------------------------------------------------------------------------------------------
    $restore = static function () use ( $before ): void {
        GlobalPreset::save_data( $before );
    };
    $restore();
    $out['r2_odd_module_key_0'] = array_map( static fn ( $i ): array => [ 'name' => $i['name'] ?? null, 'moduleName' => $i['moduleName'] ?? null, 'id' => $i['id'] ?? null ], array_values( (array) ( $before['module'][0]['items'] ?? $before['module']['0']['items'] ?? [] ) ) );

    // A. precise margin rule + the heading selectors the preset CSS targets
    $newRequest();
    GlobalPreset::process_presets_for_import( $input, true );
    $newRequest();
    $css2 = $render( (int) $pageId );
    $out['r2_margin_rule'] = preg_match_all( '/[^{}]{0,120}preset--module--divi-heading--' . preg_quote( $newId, '/' ) . '[^{}]{0,160}\{[^}]{0,200}\}/', $css2, $mm ) ? array_slice( $mm[0], 0, 4 ) : [];

    // B. update semantics: same id, different attrs and name
    $style2 = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '61px' ] ] ] ] ] ] ];
    $item2  = array_merge( $item, [ 'name' => 'AIED probe heading v2', 'attrs' => $style2, 'styleAttrs' => $style2 ] );
    $input2 = [ 'module' => [ 'divi/heading' => [ 'default' => $out['heading_default_id'], 'items' => [ $newId => $item2 ] ] ] ];
    $newRequest();
    GlobalPreset::process_presets_for_import( $input2, true );
    $stored2 = GlobalPreset::get_data()['module']['divi/heading']['items'][ $newId ] ?? null;
    $out['r2_update_via_import'] = [ 'name' => $stored2['name'] ?? null, 'size' => $stored2['styleAttrs']['title']['decoration']['font']['font']['desktop']['value']['size'] ?? null, 'updated_ts' => $stored2['updated'] ?? null, 'created_ts' => $stored2['created'] ?? null, 'count' => GlobalPreset::count_presets( GlobalPreset::get_data() )['total'] ];
    $cur = GlobalPreset::get_data();
    $cur['module']['divi/heading']['items'][ $newId ] = $item2;
    GlobalPreset::save_data( $cur );
    $stored3 = GlobalPreset::get_data()['module']['divi/heading']['items'][ $newId ] ?? null;
    $out['r2_update_via_save_data'] = [ 'name' => $stored3['name'] ?? null, 'size' => $stored3['styleAttrs']['title']['decoration']['font']['font']['desktop']['value']['size'] ?? null, 'updated_ts' => $stored3['updated'] ?? null, 'created_ts' => $stored3['created'] ?? null ];
    $restore();

    // C. a module that has NO presets yet (divi/image): what else does the import create?
    $imgStyle = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'top' => '', 'right' => '', 'bottom' => '12px', 'left' => '', 'syncVertical' => 'off', 'syncHorizontal' => 'off' ] ] ] ] ] ] ];
    $imgId    = wp_generate_uuid4();
    $imgItem  = array_merge( $tpl, [ 'id' => $imgId, 'moduleName' => 'divi/image', 'name' => 'AIED probe image', 'attrs' => $imgStyle, 'styleAttrs' => $imgStyle ] );
    unset( $imgItem['renderAttrs'] );
    $out['r2_image_had_presets_before'] = isset( $before['module']['divi/image'] );
    $newRequest();
    $resImg = GlobalPreset::process_presets_for_import( [ 'module' => [ 'divi/image' => [ 'default' => '', 'items' => [ $imgId => $imgItem ] ] ] ], true );
    $afterImg = GlobalPreset::get_data();
    $out['r2_new_module'] = [
        'mappings'                => $resImg['preset_id_mappings'] ?? null,
        'defaultImportedModule'   => $resImg['defaultImportedModulePresetIds'] ?? null,
        'counts'                  => GlobalPreset::count_presets( $afterImg ),
        'divi_image_entry'        => isset( $afterImg['module']['divi/image'] ) ? [ 'default' => $afterImg['module']['divi/image']['default'] ?? null, 'items' => array_map( static fn ( $i ): array => [ 'name' => $i['name'] ?? null, 'version' => $i['version'] ?? null ], array_values( (array) $afterImg['module']['divi/image']['items'] ) ) ] : null,
        'everything_else_changed' => $diffPaths( $before, array_diff_key( $afterImg, [] ) ),
    ];
    $restore();

    // D. version field: what does Divi keep when we pass the current version?
    $itemV = array_merge( $item, [ 'version' => ET_BUILDER_VERSION ] );
    $newRequest();
    GlobalPreset::process_presets_for_import( [ 'module' => [ 'divi/heading' => [ 'default' => $out['heading_default_id'], 'items' => [ $newId => $itemV ] ] ] ], true );
    $out['r2_version_kept'] = GlobalPreset::get_data()['module']['divi/heading']['items'][ $newId ]['version'] ?? null;
    $restore();

    // E. custom CSS inside a preset: does Divi render it? (the free plugin refuses such code in layouts)
    $css9 = [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.aiedcss9{outline:9px solid red}', 'mainElement' => 'outline:7px solid blue' ] ] ] ] ] ];
    $itemC = array_merge( $item, [ 'attrs' => $css9, 'styleAttrs' => $css9 ] );
    $newRequest();
    GlobalPreset::process_presets_for_import( [ 'module' => [ 'divi/heading' => [ 'default' => $out['heading_default_id'], 'items' => [ $newId => $itemC ] ] ] ], true );
    $out['r2_css_stored'] = isset( GlobalPreset::get_data()['module']['divi/heading']['items'][ $newId ]['styleAttrs']['module']['advanced']['css'] );
    $newRequest();
    $css3 = $render( (int) $pageId );
    $out['r2_css_rendered'] = [ 'freeForm_outline_9px' => false !== strpos( $css3, 'outline:9px solid red' ), 'mainElement_outline_7px' => false !== strpos( $css3, 'outline:7px solid blue' ), 'contexts' => array_merge( $context( $css3, 'outline:9px', 1 ), $context( $css3, 'outline:7px', 1 ) ) ];
    $restore();
} catch ( \Throwable $e ) {
    $out['aborted'] = get_class( $e ) . ': ' . $e->getMessage();
} finally {
    foreach ( $pages as $pid ) {
        if ( null !== get_post( $pid ) ) {
            wp_delete_post( $pid, true );
        }
    }
    et_update_option( $optionName, $snapshot, false, '', '', true );
    if ( class_exists( 'ET_Core_PageResource' ) ) {
        ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
    }
    $out['cleanup'] = [
        'raw_restored_byte_identical' => serialize( $raw() ) === $snapshotS,
        'throwaway_pages_left'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied preset spike%'" ), // phpcs:ignore
    ];
}
WP_CLI::log( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
if ( ! ( $out['cleanup']['raw_restored_byte_identical'] ?? false ) || 0 !== ( $out['cleanup']['throwaway_pages_left'] ?? 1 ) ) {
    WP_CLI::error( 'The stored presets were NOT restored byte for byte or a page is left: fix this by hand before anything else (dev site).' );
}
WP_CLI::success( isset( $out['aborted'] ) ? 'Spike aborted (see JSON) but the site is as it was.' : 'Presets spike finished; the stored presets are exactly as they were.' );
