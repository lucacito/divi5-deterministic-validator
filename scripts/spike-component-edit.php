<?php
/**
 * Spike (dev site only): what does Divi 5.15 render when a page's component INSTANCE holds a different value than the
 * component itself for a block the component owns (a "synced" block)? Recreates the section component from
 * fixtures/components/library-5.15.json, saves an instance whose heading text was changed in the page content, renders it,
 * and prints which text shows. Everything it creates is deleted. Run: wp eval-file /scripts/spike-component-edit.php --user=1
 */
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
$lib = json_decode( (string) file_get_contents( '/fixtures/components/library-5.15.json' ), true );
$t   = $lib['templates']['section-with-slot'];
$ids = [];
$new = wp_insert_post( [ 'post_type' => 'et_pb_layout', 'post_status' => 'publish', 'post_title' => 'aied spike comp', 'post_content' => wp_slash( $t['post_content'] ) ] );
$ids[] = $new;
wp_set_object_terms( $new, $t['scope'], 'scope' );
wp_set_object_terms( $new, $t['layout_type'], 'layout_type' );
foreach ( $t['meta'] as $k => $v ) {
    update_post_meta( $new, $k, $v );
}
$mt  = $lib['templates']['module'];
$mid = wp_insert_post( [ 'post_type' => 'et_pb_layout', 'post_status' => 'publish', 'post_title' => 'aied spike module comp', 'post_content' => wp_slash( $mt['post_content'] ) ] );
$ids[] = $mid;
wp_set_object_terms( $mid, $mt['scope'], 'scope' );
wp_set_object_terms( $mid, $mt['layout_type'], 'layout_type' );
foreach ( $mt['meta'] as $k => $v ) {
    update_post_meta( $mid, $k, $v );
}
$fx   = json_decode( (string) file_get_contents( '/fixtures/valid/component-instance-plain-5.15.json' ), true );
$base = str_replace( '"globalModule":"' . $t['id'] . '"', '"globalModule":"' . $new . '"', (string) $fx['post_content'] );
$render = static function ( string $content ) use ( &$ids ): string {
    $pid   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied spike page', 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ] );
    $ids[] = $pid;
    query_posts( [ 'p' => $pid, 'post_type' => 'page', 'post_status' => 'any' ] );
    if ( have_posts() ) {
        the_post();
    }
    $html = (string) apply_filters( 'the_content', get_post( $pid )->post_content );
    wp_reset_query();

    return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
};
try {
    echo "A  untouched instance      : ", $render( $base ), "\n";
    $changed = str_replace( 'Component probe', 'CHANGED IN PAGE', $base );
    echo "B  synced heading changed  : ", $render( $changed ), "\n";
    $local = preg_replace( '/("title":\{"innerContent":\{"desktop":\{"value":"CHANGED IN PAGE"\}\}\},"builderVersion":"5.15.1","syncNodeId":"[^"]+","localAttrOverridePaths":)\[\]/', '$1["title"]', $changed );
    echo "C  + override path [title] : ", $render( $local ), "\n";
    $local2 = str_replace( 'Some body text', 'TEXT CHANGED IN PAGE', $changed );
    $gone = str_replace( '"globalModule":"' . $new . '"', '"globalModule":"99999999"', $base );
    echo "E  component id that does not exist: ", $render( $gone ), "\n";
    $mfx  = json_decode( (string) file_get_contents( '/fixtures/valid/component-instance-module-5.15.json' ), true );
    $mbase = str_replace( '"globalModule":"' . $mt['id'] . '"', '"globalModule":"' . $mid . '"', (string) $mfx['post_content'] );
    echo "F  module instance untouched  : ", $render( $mbase ), "\n";
    echo "G  module instance localAttrs title changed: ", $render( str_replace( 'Module component probe', 'LOCAL TITLE CHANGED', $mbase ) ), "\n";
    echo "D  second synced block     : ", $render( $local2 ), "\n";
} finally {
    foreach ( $ids as $id ) {
        wp_delete_post( $id, true );
    }
}
