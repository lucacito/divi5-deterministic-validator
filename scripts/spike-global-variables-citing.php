<?php
/**
 * ONE-OFF evidence spike (phase B of manage_global_variables): can a layout cite a global NUMBERS and a global STRINGS variable?
 * Through the real Pro registry it creates one throwaway number and one throwaway string variable, builds three throwaway published
 * pages that cite them in the form Divi's own source documents ($variable({"type":"content","value":{"name":"gvid-...","settings":{}}})$):
 *   A  both variables active, B  both archived, C  a cited id that does not exist at all
 * and records what Divi renders (the page HTML plus its same-site stylesheets) and what our gate (LayoutGate = validator + code policy) says.
 * It then deletes the pages, undoes the batches and proves the stored variables are identical to the start.
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-global-variables-citing.php --user=1
 * Dev sites only (scripts/dev-guard.php). The free plugin and the Pro add-on must be active. Set AIED_LOOPBACK_BASE if http://wordpress is not reachable.
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\LayoutGate;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryTable::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
if ( ! GlobalVariables::available() ) {
    WP_CLI::error( "Divi 5's variable functions are not available: activate the Divi theme first." );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

global $wpdb;
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
$batchIds = [];
$newRequest = static function (): void {
    if ( class_exists( 'ET_Core_PageResource' ) && property_exists( 'ET_Core_PageResource', '_global_timestamp_updated' ) ) {
        $prop = new ReflectionProperty( 'ET_Core_PageResource', '_global_timestamp_updated' );
        $prop->setAccessible( true );
        $prop->setValue( null, false );
    }
};
$call = static function ( string $name, array $args ) use ( $registry, &$batchIds, $newRequest ): array {
    $newRequest();
    $res = $registry->claim( null, $name, $args, 1 );
    if ( ! $res instanceof WP_REST_Response ) {
        throw new RuntimeException( 'tool not claimed: ' . $name );
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        throw new RuntimeException( $name . ': ' . ( $data['error']['message'] ?? 'error' ) );
    }
    $result = (array) json_decode( (string) ( $data['result']['content'][0]['text'] ?? '' ), true );
    foreach ( [ 'batch', 'undo_batch' ] as $k ) {
        if ( isset( $result[ $k ]['id'] ) ) {
            $batchIds[] = (int) $result[ $k ]['id'];
        }
    }

    return $result;
};
$apply = static function ( array $variables ) use ( $call ): array {
    $d = $call( 'manage_global_variables', [ 'variables' => $variables, 'dry_run' => true ] );

    return $call( 'manage_global_variables', [ 'variables' => $variables, 'preview_token' => $d['preview_token'] ] );
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
        $out[] = substr( $haystack, max( 0, $pos - 100 ), strlen( $needle ) + 200 );
        $off   = $pos + strlen( $needle );
    }

    return $out;
};
$cite = static fn ( string $id ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$';
$layout = static function ( string $numId, string $strId ) use ( $cite ): string {
    $section = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => $cite( $numId ), 'bottom' => '20px', 'syncVertical' => 'off' ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];
    $head    = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $cite( $strId ) ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $cite( $numId ) ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section ' . serialize_block_attributes( $section ) . ' --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $head ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$literal = static function ( string $text, string $pad, string $size ): string {
    $section = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => $pad, 'bottom' => '20px', 'syncVertical' => 'off' ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];
    $head    = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $size ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section ' . serialize_block_attributes( $section ) . ' --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $head ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$build = static function ( string $pad, string $size, string $head, string $text ): string {
    $section = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => $pad, 'bottom' => '20px', 'syncVertical' => 'off' ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];
    $title   = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $head ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $size ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];
    $body    = [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section ' . serialize_block_attributes( $section ) . ' --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $title ) . ' /--><!-- wp:divi/text ' . serialize_block_attributes( $body ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$citeBA = static fn ( string $id ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => $id, 'settings' => [ 'before' => '', 'after' => '' ] ] ] ) . ')$';
$raw    = static fn ( string $id ): string => 'var(--' . $id . ')';
$snapshot = static fn (): string => md5( serialize( GlobalVariables::read() ) );

HistoryTable::ensureReady( true );
BatchTable::ensureReady( true );
$batchesStart = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ); // phpcs:ignore
$fpStart      = $snapshot();
$marker       = 'AIEDCITESTRING hello world';
$out          = [ 'number_value' => '40px', 'string_value' => $marker ];
$pages        = [];
$createB      = 0;
$archiveB     = 0;
try {
    $c       = $apply( [ [ 'type' => 'numbers', 'label' => 'AIED cite number', 'value' => '40px' ], [ 'type' => 'strings', 'label' => 'AIED cite string', 'value' => $marker ] ] );
    $numId   = (string) $c['changes'][0]['id'];
    $strId   = (string) $c['changes'][1]['id'];
    $createB = (int) $c['batch']['id'];
    $missing = 'gvid-aiedcite-0000-4000-8000-00000000dead';
    $out['ids'] = [ 'number' => $numId, 'string' => $strId, 'missing' => $missing ];

    $probe = static function ( string $label, string $content, string $numId, string $strId ) use ( &$pages, $render, $context, $marker ): array {
        $gate = LayoutGate::check( $content );
        $id   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied cite spike ' . $label, 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( $id->get_error_message() );
        }
        $pages[] = (int) $id;
        $css     = $render( (int) $id );

        return [
            'gate_valid'               => $gate->isValid(),
            'gate_violations'          => array_map( static fn ( $v ): string => method_exists( $v, 'code' ) ? (string) $v->code() : (string) json_encode( $v ), array_slice( $gate->violations(), 0, 5 ) ),
            'rendered_bytes'           => strlen( $css ),
            'served_page_id'           => preg_match( '/<body[^>]*\bpage-id-(\d+)/', $css, $mp ) ? (int) $mp[1] : null,
            'probe_page_id'            => (int) $id,
            'has_divi_section'         => false !== strpos( $css, 'et_pb_section' ),
            'number_defined'           => preg_match( '/--' . preg_quote( $numId, '/' ) . '\s*:\s*[^;}]{0,40}/i', $css, $m1 ) ? $m1[0] : null,
            'number_var_uses'          => $context( $css, 'var(--' . $numId . ')' ),
            'string_defined'           => preg_match( '/--' . preg_quote( $strId, '/' ) . '\s*:\s*[^;}]{0,60}/i', $css, $m2 ) ? $m2[0] : null,
            'string_var_uses'          => $context( $css, 'var(--' . $strId . ')' ),
            'heading_shows_string'     => false !== strpos( $css, $marker ),
            'string_marker_contexts'   => $context( $css, $marker ),
            'raw_variable_token_leaks' => substr_count( $css, '$variable(' ) + substr_count( $css, 'u0024variable(' ),
            'raw_gvid_text_in_body'    => $context( $css, '>' . $strId ),
            'heading_html'             => $context( $css, 'et_pb_heading', 1 ),
            'section_padding_css'      => preg_match_all( '/[^{}]{0,80}\.et_pb_section_\d[^{}]{0,60}\{[^}]{0,300}padding[^}]{0,200}\}/', $css, $ms ) ? array_slice( $ms[0], 0, 2 ) : [],
            'any_gvid_anywhere'        => $context( $css, 'gvid-', 2 ),
        ];
    };

    $out['D_control_literals'] = $probe( 'D', $build( '40px', '30px', $marker, '<p>' . $marker . '</p>' ), $numId, $strId );
    $out['A_content_form']     = $probe( 'A', $build( $cite( $numId ), $cite( $numId ), $cite( $strId ), $cite( $strId ) ), $numId, $strId );
    $out['E_raw_var_numbers']  = $probe( 'E', $build( $raw( $numId ), $raw( $numId ), $marker, '<p>' . $marker . '</p>' ), $numId, $strId );
    $out['F_before_after']     = $probe( 'F', $build( '40px', '30px', $citeBA( $strId ), $citeBA( $strId ) ), $numId, $strId );
    $a = $apply( [ [ 'id' => $numId, 'status' => 'archived' ], [ 'id' => $strId, 'status' => 'archived' ] ] );
    $archiveB = (int) $a['batch']['id'];
    $out['E_archived']         = $probe( 'EB', $build( $raw( $numId ), $raw( $numId ), $marker, '<p>' . $marker . '</p>' ), $numId, $strId );
    $out['F_archived']         = $probe( 'FB', $build( '40px', '30px', $citeBA( $strId ), $citeBA( $strId ) ), $numId, $strId );
    $out['E_missing']          = $probe( 'EC', $build( $raw( $missing ), $raw( $missing ), $marker, '<p>' . $marker . '</p>' ), $missing, $missing );
} catch ( \Throwable $e ) {
    $out['aborted'] = get_class( $e ) . ': ' . $e->getMessage();
} finally {
    foreach ( $pages as $pid ) {
        if ( null !== get_post( $pid ) ) {
            wp_delete_post( $pid, true );
        }
    }
    foreach ( array_filter( [ $archiveB, $createB ] ) as $b ) { // newest first
        try {
            $call( 'undo_batch', [ 'batch_id' => $b ] );
        } catch ( \Throwable $e ) {
            $out['undo_error'][] = $b . ': ' . $e->getMessage();
        }
    }
    $batchIds = array_values( array_unique( $batchIds ) );
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
    }
    $left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied cite spike%'" ); // phpcs:ignore
    $out['cleanup'] = [ 'variables_identical_to_start' => $snapshot() === $fpStart, 'throwaway_pages_left' => $left, 'batch_rows_start' => $batchesStart, 'batch_rows_now' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ) ]; // phpcs:ignore
}
WP_CLI::log( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
if ( isset( $out['aborted'] ) || ! ( $out['cleanup']['variables_identical_to_start'] ?? false ) || 0 !== ( $out['cleanup']['throwaway_pages_left'] ?? 1 ) ) {
    WP_CLI::error( 'The spike aborted or did not clean up: look at the JSON above and fix the dev site by hand.' );
}
WP_CLI::success( 'Citing spike finished; the dev site is as it was.' );
