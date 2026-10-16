<?php
/**
 * Spike (dev site only): which odd values in a spacing field break the page's generated CSS on this Divi, and does our
 * style-value guard already refuse them? Section A holds the candidate padding-top, section B a marker (33px). A value "breaks"
 * the layout when the marker's own rule is missing from the page's CSS or A's output swallows a later rule.
 * Run: wp eval-file /scripts/spike-style-values.php --user=1   (everything it creates is deleted)
 */
use AiEditorDivi5\WP\LayoutGate;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
$ids   = [];
$fetch = static function ( string $url ) use ( &$fetch ): string {
    $p    = wp_parse_url( $url );
    $host = ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    $res  = wp_remote_get( 'http://wordpress' . $path, [ 'headers' => [ 'Host' => $host ], 'timeout' => 30, 'redirection' => 0 ] );

    return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
};
$section = static fn ( string $pad ): string => '<!-- wp:divi/section ' . serialize_block_attributes( [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => $pad, 'bottom' => '20px', 'syncVertical' => 'off' ] ] ] ] ] ], 'builderVersion' => '5.15.1' ] ) . ' --><!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"builderVersion":"5.15.1"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"x"}}},"builderVersion":"5.15.1"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
try {
    foreach ( [ '10px', 'abc', '10 px', '10px;', '10px}', '10px<', "10px'", '10px"', '10px!important', 'calc(10px', '1e3px', '-', '', '10,5px', "10px\n20px" ] as $val ) {
        $content = '<!-- wp:divi/placeholder -->' . $section( $val ) . $section( '33px' ) . '<!-- /wp:divi/placeholder -->';
        $gate    = LayoutGate::check( $content );
        $pid     = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied spike style', 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ] );
        $ids[]   = $pid;
        $html    = $fetch( get_permalink( $pid ) );
        $css     = $html;
        if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+\.css[^\'"]*)[\'"]/i', $html, $m ) ) {
            foreach ( array_slice( array_unique( $m[1] ), 0, 25 ) as $h ) {
                if ( wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( $h, PHP_URL_HOST ) ) {
                    $css .= $fetch( $h );
                }
            }
        }
        $marker = 1 === preg_match( '/padding-top:\s*33px/', $css );
        $a      = preg_match( '/et_pb_section_0\.et_pb_section\{[^}]*\}/', $css, $am ) ? $am[0] : '(no rule for section A)';
        printf( "%-16s guard:%-8s marker rule present:%-3s A: %s\n", json_encode( $val ), $gate->isValid() ? 'accepts' : 'refuses', $marker ? 'yes' : 'NO', substr( $a, 0, 90 ) );
    }
} finally {
    foreach ( $ids as $id ) {
        wp_delete_post( $id, true );
    }
}
