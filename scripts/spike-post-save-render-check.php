<?php
/**
 * ONE-OFF evidence spike (backlog 2e, post-save check): can a tool look at how a saved page RENDERS and report problems the
 * checker cannot see (an empty module from a dead custom-field binding, an empty section, a missing module), without a
 * screenshot, and how well does that work without a network request? Four probes, JSON on stdout:
 *   A  in-process render (the_content on the page's own content, no HTTP) of a good published page and a good DRAFT page
 *   B  the loopback alternative: GET the site's own URL (what hosts commonly block) and the failure it gives here
 *   C  an "empty module" heuristic over EVERY real Divi 5 page on the dev site: how many modules, how many flagged, which types
 *   D  deliberately broken layouts (dead binding, empty section, unknown module, code module, truncated markup)
 * Creates and always deletes its throwaway pages. Dev sites only (scripts/dev-guard.php).
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-post-save-render-check.php --user=1
 */

use AiEditorDivi5\WP\LayoutGate;

if ( ! class_exists( LayoutGate::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$V     = '5.14.0';
$wrap  = static fn ( string $inner ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $V . '"} --><!-- wp:divi/row {"builderVersion":"' . $V . '"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"' . $V . '"} -->' . $inner . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$text  = static fn ( string $value ): string => '<!-- wp:divi/text ' . serialize_block_attributes( [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $value ] ] ], 'builderVersion' => $V ] ) . ' /-->';
$bound = static fn ( string $field ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'before' => '', 'after' => '', 'select_meta_key' => 'custom_meta_' . $field, 'meta_key' => '', 'date_format' => 'default', 'custom_date_format' => '', 'enable_html' => 'off' ] ] ] ) . ')$';

/** Render a post's own content in-process, as the front end would for that post, capturing PHP notices and the time taken. */
$render = static function ( int $id ): array {
    $post = get_post( $id );
    if ( ! $post instanceof WP_Post ) {
        return [ 'html' => '', 'ms' => 0, 'notices' => [ 'no such post' ], 'error' => 'no such post' ];
    }
    $notices = [];
    $prev    = set_error_handler( static function ( int $no, string $str ) use ( &$notices ): bool {
        $notices[] = substr( $str, 0, 160 );

        return true;
    } );
    $html  = '';
    $error = null;
    $t0    = microtime( true );
    try {
        query_posts( [ 'p' => $id, 'post_type' => $post->post_type, 'post_status' => 'any' ] );
        if ( have_posts() ) {
            the_post();
        }
        $html = (string) apply_filters( 'the_content', $post->post_content );
    } catch ( Throwable $e ) {
        $error = get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 160 );
    } finally {
        wp_reset_query();
        restore_error_handler();
    }

    return [ 'html' => $html, 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ), 'notices' => array_values( array_unique( $notices ) ), 'error' => $error ];
};

/** Structural summary of rendered module HTML: counts, and modules / sections that rendered with nothing in them. */
$summarise = static function ( string $html ): array {
    $out = [ 'bytes' => strlen( $html ), 'sections' => 0, 'modules' => 0, 'empty_modules' => [], 'empty_sections' => 0, 'raw_token_leaks' => substr_count( $html, '$variable(' ) + substr_count( $html, 'u0024variable(' ), 'block_comment_leaks' => substr_count( $html, '<!-- wp:' ) ];
    if ( '' === trim( $html ) ) {
        return $out;
    }
    $dom  = new DOMDocument();
    $prev = libxml_use_internal_errors( true );
    $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="aied-root">' . $html . '</div>' );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );
    $xp = new DOMXPath( $dom );
    $hasClass = static fn ( string $c ): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ')";
    $out['sections'] = $xp->query( '//*[' . $hasClass( 'et_pb_section' ) . ']' )->length;
    foreach ( $xp->query( '//*[' . $hasClass( 'et_pb_module' ) . ']' ) as $node ) {
        ++$out['modules'];
        $class = (string) $node->getAttribute( 'class' );
        $type  = 'unknown';
        foreach ( preg_split( '/\s+/', $class ) ?: [] as $c ) {
            if ( preg_match( '/^et_pb_([a-z0-9_]+?)_\d+(?:_tb_\w+)?$/', $c, $m ) ) {
                $type = $m[1];
                break;
            }
        }
        $text     = trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) ?? '' );
        $visual   = $xp->query( './/img|.//svg|.//iframe|.//video|.//audio|.//canvas|.//input|.//textarea|.//select|.//object|.//embed|.//picture|.//form|.//*[contains(@class,"icon")]|.//*[contains(@class,"et_pb_image")]', $node )->length;
        $children = $xp->query( './/*[' . $hasClass( 'et_pb_module' ) . ']', $node )->length;
        if ( '' === $text && 0 === $visual && 0 === $children ) {
            $out['empty_modules'][ $type ] = ( $out['empty_modules'][ $type ] ?? 0 ) + 1;
        }
    }
    foreach ( $xp->query( '//*[' . $hasClass( 'et_pb_section' ) . ']' ) as $node ) {
        if ( 0 === $xp->query( './/*[' . $hasClass( 'et_pb_module' ) . ']', $node )->length ) {
            ++$out['empty_sections'];
        }
    }

    return $out;
};

$pages = [];
$mk    = static function ( string $title, string $content, string $status = 'publish' ) use ( &$pages ): int {
    $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title, 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $id ) ) {
        throw new RuntimeException( $id->get_error_message() );
    }
    $pages[] = (int) $id;

    return (int) $id;
};
$out = [ 'divi' => defined( 'ET_BUILDER_PRODUCT_VERSION' ) ? ET_BUILDER_PRODUCT_VERSION : null ];

try {
    // ---- A: in-process render, published and draft ----------------------------------------------------------------
    $good = $wrap( $text( 'AIED-RENDER-GOOD' ) );
    $pub  = $mk( 'aied render good published', $good, 'publish' );
    $drf  = $mk( 'aied render good draft', $good, 'draft' );
    foreach ( [ 'A_published' => $pub, 'A_draft' => $drf ] as $k => $id ) {
        $r         = $render( $id );
        $out[ $k ] = [ 'ms' => $r['ms'], 'error' => $r['error'], 'notices' => $r['notices'], 'has_marker' => str_contains( $r['html'], 'AIED-RENDER-GOOD' ), 'summary' => $summarise( $r['html'] ) ];
    }

    // ---- B: loopback ------------------------------------------------------------------------------------------------
    $plain = wp_remote_get( add_query_arg( 'page_id', $pub, home_url( '/' ) ), [ 'timeout' => 10, 'redirection' => 0, 'sslverify' => false ] );
    $out['B_loopback_home_url'] = is_wp_error( $plain ) ? [ 'ok' => false, 'error' => substr( $plain->get_error_message(), 0, 200 ) ] : [ 'ok' => true, 'status' => wp_remote_retrieve_response_code( $plain ) ];
    $base = rtrim( (string) ( getenv( 'AIED_LOOPBACK_BASE' ) ?: 'http://wordpress' ), '/' );
    $host = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
    $via  = wp_remote_get( $base . '/?page_id=' . $pub, [ 'headers' => [ 'Host' => $host ], 'timeout' => 20, 'redirection' => 0 ] );
    $out['B_loopback_container_host'] = is_wp_error( $via ) ? [ 'ok' => false, 'error' => substr( $via->get_error_message(), 0, 200 ) ] : [ 'ok' => true, 'status' => wp_remote_retrieve_response_code( $via ), 'marker' => str_contains( (string) wp_remote_retrieve_body( $via ), 'AIED-RENDER-GOOD' ), 'bytes' => strlen( (string) wp_remote_retrieve_body( $via ) ) ];
    $dv = wp_remote_get( $base . '/?page_id=' . $drf, [ 'headers' => [ 'Host' => $host ], 'timeout' => 20, 'redirection' => 0 ] );
    $out['B_loopback_draft_no_login'] = is_wp_error( $dv ) ? [ 'ok' => false, 'error' => substr( $dv->get_error_message(), 0, 200 ) ] : [ 'ok' => true, 'status' => wp_remote_retrieve_response_code( $dv ), 'marker' => str_contains( (string) wp_remote_retrieve_body( $dv ), 'AIED-RENDER-GOOD' ), 'location' => (string) wp_remote_retrieve_header( $dv, 'location' ) ];

    // ---- D: deliberately broken layouts -----------------------------------------------------------------------------
    $cases = [
        'D1_dead_custom_field' => $wrap( $text( $bound( 'aied_missing_field_zzz' ) ) . $text( 'AIED-NEIGHBOUR' ) ),
        'D2_empty_section'     => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"' . $V . '"} --><!-- wp:divi/row {"builderVersion":"' . $V . '"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"' . $V . '"} --><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
        'D3_unknown_module'    => $wrap( '<!-- wp:divi/not-a-module {"builderVersion":"' . $V . '"} /-->' . $text( 'AIED-NEIGHBOUR' ) ),
        'D4_code_module'       => $wrap( '<!-- wp:divi/code ' . serialize_block_attributes( [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<b>AIED-CODE</b>' ] ] ], 'builderVersion' => $V ] ) . ' /-->' ),
        'D5_truncated_markup'  => substr( $wrap( $text( 'AIED-NEIGHBOUR' ) ), 0, 260 ),
        'D6_empty_text'        => $wrap( $text( '' ) . $text( 'AIED-NEIGHBOUR' ) ),
    ];
    foreach ( $cases as $label => $content ) {
        $id  = $mk( 'aied render ' . $label, $content );
        $r   = $render( $id );
        $gate = LayoutGate::check( $content );
        $out[ $label ] = [ 'gate_valid' => $gate->isValid(), 'gate_codes' => array_values( array_unique( array_map( static fn ( $v ): string => (string) ( $v->toArray()['code'] ?? '' ), $gate->violations() ) ) ), 'ms' => $r['ms'], 'error' => $r['error'], 'notices' => $r['notices'], 'neighbour_rendered' => str_contains( $r['html'], 'AIED-NEIGHBOUR' ), 'summary' => $summarise( $r['html'] ), 'html_head' => substr( preg_replace( '/\s+/', ' ', $r['html'] ) ?? '', 0, 220 ) ];
    }

    // ---- C: the heuristic over every real Divi 5 page already on the site -------------------------------------------
    $ids = get_posts( [ 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids', 'meta_key' => '_et_pb_use_divi_5', 'meta_value' => 'on', 'orderby' => 'ID' ] );
    $corpus = [ 'pages' => 0, 'modules' => 0, 'sections' => 0, 'empty_sections' => 0, 'empty_modules_by_type' => [], 'pages_with_findings' => 0, 'render_errors' => 0, 'notice_pages' => 0, 'total_ms' => 0, 'max_ms' => 0, 'examples' => [] ];
    foreach ( $ids as $id ) {
        if ( in_array( (int) $id, $pages, true ) ) {
            continue;
        }
        $r = $render( (int) $id );
        $s = $summarise( $r['html'] );
        ++$corpus['pages'];
        $corpus['modules']        += $s['modules'];
        $corpus['sections']       += $s['sections'];
        $corpus['empty_sections'] += $s['empty_sections'];
        $corpus['total_ms']       += $r['ms'];
        $corpus['max_ms']          = max( $corpus['max_ms'], $r['ms'] );
        $corpus['render_errors']  += null === $r['error'] ? 0 : 1;
        $corpus['notice_pages']   += [] === $r['notices'] ? 0 : 1;
        foreach ( $s['empty_modules'] as $type => $n ) {
            $corpus['empty_modules_by_type'][ $type ] = ( $corpus['empty_modules_by_type'][ $type ] ?? 0 ) + $n;
        }
        if ( [] !== $s['empty_modules'] || $s['empty_sections'] > 0 ) {
            ++$corpus['pages_with_findings'];
            if ( count( $corpus['examples'] ) < 6 ) {
                $corpus['examples'][] = [ 'id' => (int) $id, 'title' => substr( (string) get_the_title( (int) $id ), 0, 40 ), 'empty_modules' => $s['empty_modules'], 'empty_sections' => $s['empty_sections'], 'modules' => $s['modules'] ];
            }
        }
    }
    arsort( $corpus['empty_modules_by_type'] );
    $out['C_corpus'] = $corpus;
} finally {
    foreach ( $pages as $p ) {
        wp_delete_post( $p, true );
    }
    $out['leftovers'] = count( get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 's' => 'aied render', 'numberposts' => -1 ] ) );
}

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
