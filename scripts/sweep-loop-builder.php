<?php
/**
 * Spike: what Divi 5.15.1 does with a Loop Builder module (module.advanced.loop) saved by hand.
 *
 * Shapes come from Divi's own sources (LoopUtils.php reads, ai-agent.js documents `module.advanced.loop.desktop.value`).
 * Saves ONE case as a throwaway draft page on the dev site, renders it and prints what came out; asserts nothing.
 * One case per process, because a render-fatal PHP error ends the process.
 * Every page it creates is titled 'aied spike loop ...' and deleted again.
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-loop-builder.php <case|list> --user=1
 */

use AiEditorDivi5\WP\LayoutGate;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$tok = static function ( string $name ): string {
    return '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => $name, 'settings' => new stdClass() ] ] ) . ')$';
};
$block = static function ( string $name, array $attrs, ?string $inner = null ): string {
    $json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );

    return null === $inner
        ? "<!-- wp:divi/{$name} {$json} /-->\n"
        : "<!-- wp:divi/{$name} {$json} -->\n{$inner}<!-- /wp:divi/{$name} -->\n";
};
$wrap = static function ( string $inner ) use ( $block ): string {
    $col = $block( 'column', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ], 'builderVersion' => '5.15.1' ], $inner );
    $row = $block( 'row', [ 'builderVersion' => '5.15.1' ], $col );

    return $block( 'section', [ 'builderVersion' => '5.15.1' ], $row );
};
$heading = static function ( string $var ) use ( $block, $tok ): string {
    return $block( 'heading', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $tok( $var ) ] ] ], 'builderVersion' => '5.15.1' ] );
};
$withLoop = static function ( $loopValue, array $extra = [] ): array {
    return array_replace_recursive( [ 'module' => [ 'advanced' => [ 'loop' => $loopValue ] ], 'builderVersion' => '5.15.1' ], $extra );
};
$posts = static function ( array $over = [] ): array {
    return array_replace( [
        'enable'      => 'on',
        'loopId'      => 'loop-spike0001',
        'queryType'   => 'post_types',
        'subTypes'    => [ [ 'value' => 'post', 'label' => 'Posts' ] ],
        'orderBy'     => 'date',
        'order'       => 'descending',
        'postPerPage' => '3',
        'postOffset'  => '0',
    ], $over );
};
$group = static function ( $loopValue, string $inner ) use ( $block, $withLoop ): string {
    return $block( 'group', $withLoop( $loopValue ), $inner );
};
$ok      = static function ( array $v ) use ( $group, $wrap, $heading ): string {
    return $wrap( $group( [ 'desktop' => [ 'value' => $v ] ], $heading( 'loop_post_title' ) ) );
};
$okLoop  = static function ( $loop, string $var = 'loop_post_title' ) use ( $group, $wrap, $heading ): string {
    return $wrap( $group( $loop, $heading( $var ) ) );
};
$wrapV   = static function ( array $v ): array {
    return [ 'desktop' => [ 'value' => $v ] ];
};

$cases = [
    // ---- shapes the builder writes ----
    'posts'            => static fn() => $ok( $posts() ),
    'pages'            => static fn() => $ok( $posts( [ 'subTypes' => [ [ 'value' => 'page', 'label' => 'Pages' ] ] ] ) ),
    'products'         => static fn() => $wrap( $group( $wrapV( $posts( [ 'subTypes' => [ [ 'value' => 'product', 'label' => 'Products' ] ] ] ) ), $heading( 'loop_product_title' ) ) ),
    'terms'            => static fn() => $wrap( $group( $wrapV( $posts( [ 'queryType' => 'post_taxonomies', 'subTypes' => [ [ 'value' => 'category', 'label' => 'Categories' ] ], 'orderBy' => 'name', 'order' => 'ascending' ] ) ), $heading( 'loop_term_name' ) ) ),
    'users'            => static fn() => $wrap( $group( $wrapV( $posts( [ 'queryType' => 'user_roles', 'subTypes' => [ [ 'value' => 'administrator', 'label' => 'Administrator' ] ], 'orderBy' => 'display_name', 'order' => 'ascending' ] ) ), $heading( 'loop_user_name' ) ) ),
    'current-page'     => static fn() => $wrap( $group( $wrapV( $posts( [ 'queryType' => 'current_page', 'subTypes' => [] ] ) ), $heading( 'loop_post_title' ) ) ),
    'offset-and-sticky' => static fn() => $ok( $posts( [ 'postOffset' => '1', 'excludeCurrentPost' => 'on', 'ignoreStickysPost' => 'on' ] ) ),
    'meta-query'       => static fn() => $ok( $posts( [ 'metaQuery' => [ [ 'id' => 'mq1', 'metaKey' => '_thumbnail_id', 'metaValue' => '', 'compare' => 'EXISTS', 'type' => 'CHAR' ] ] ] ) ),
    'include-ids'      => static fn() => $ok( $posts( [ 'includeSpecificPosts' => [ [ 'value' => '1', 'label' => 'x' ] ] ] ) ),
    'term-filter'      => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => [ [ 'categoryId' => 'category', 'categoryName' => 'Categories', 'selectedOptions' => [ [ 'value' => '1', 'label' => 'Uncategorized' ] ] ] ] ] ) ),
    'on-row'           => static fn() => $block( 'section', [ 'builderVersion' => '5.15.1' ], $block( 'row', $withLoop( $wrapV( $posts() ) ), $block( 'column', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ], 'builderVersion' => '5.15.1' ], $heading( 'loop_post_title' ) ) ) ),
    'on-text'          => static fn() => $wrap( $block( 'text', $withLoop( $wrapV( $posts() ), [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>' . $tok( 'loop_post_title' ) . '</p>' ] ] ] ] ) ) ),
    'image-and-button' => static fn() => $wrap( $group( $wrapV( $posts() ),
        $block( 'image', [ 'image' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'src' => $tok( 'loop_post_featured_image' ), 'alt' => '' ] ] ] ], 'builderVersion' => '5.15.1' ] )
        . $heading( 'loop_post_title' )
        . $block( 'button', [ 'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => 'Read', 'linkUrl' => $tok( 'loop_post_link' ) ] ] ] ], 'builderVersion' => '5.15.1' ] ) ) ),
    'same-id-twice'    => static fn() => $wrap( $group( $wrapV( $posts() ), $heading( 'loop_post_title' ) ) . $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) ),
    'post-token-in-loop' => static fn() => $wrap( $group( $wrapV( $posts() ), $heading( 'post_title' ) ) ),

    // ---- wrong shapes (what could a model write) ----
    'enable-true'      => static fn() => $ok( $posts( [ 'enable' => true ] ) ),
    'enable-yes'       => static fn() => $ok( $posts( [ 'enable' => 'yes' ] ) ),
    'enable-missing'   => static fn() => $ok( array_diff_key( $posts(), [ 'enable' => 1 ] ) ),
    'subtypes-string'  => static fn() => $ok( $posts( [ 'subTypes' => 'post' ] ) ),
    'subtypes-object'  => static fn() => $ok( $posts( [ 'subTypes' => [ 'value' => 'post', 'label' => 'Posts' ] ] ) ),
    'subtypes-strings' => static fn() => $ok( $posts( [ 'subTypes' => [ 'post' ] ] ) ),
    'subtypes-missing' => static fn() => $ok( array_diff_key( $posts(), [ 'subTypes' => 1 ] ) ),
    'subtypes-badslug' => static fn() => $ok( $posts( [ 'subTypes' => [ [ 'value' => 'nope', 'label' => 'Nope' ] ] ] ) ),
    'ppp-int'          => static fn() => $ok( $posts( [ 'postPerPage' => 3 ] ) ),
    'ppp-text'         => static fn() => $ok( $posts( [ 'postPerPage' => 'abc' ] ) ),
    'ppp-zero'         => static fn() => $ok( $posts( [ 'postPerPage' => '0' ] ) ),
    'ppp-minus'        => static fn() => $ok( $posts( [ 'postPerPage' => '-1' ] ) ),
    'ppp-huge'         => static fn() => $ok( $posts( [ 'postPerPage' => '5000' ] ) ),
    'querytype-bad'    => static fn() => $ok( $posts( [ 'queryType' => 'products' ] ) ),
    'order-desc'       => static fn() => $ok( $posts( [ 'order' => 'desc' ] ) ),
    'orderby-bad'      => static fn() => $ok( $posts( [ 'orderBy' => 'banana' ] ) ),
    'orderby-array'    => static fn() => $ok( $posts( [ 'orderBy' => [ 'date' ] ] ) ),
    'loopid-missing'   => static fn() => $ok( array_diff_key( $posts(), [ 'loopId' => 1 ] ) ),
    'loopid-int'       => static fn() => $ok( $posts( [ 'loopId' => 5 ] ) ),
    'loopid-plain'     => static fn() => $ok( $posts( [ 'loopId' => 'abc' ] ) ),
    'loop-string'      => static fn() => $okLoop( 'on' ),
    'loop-no-value'    => static fn() => $okLoop( [ 'desktop' => [ 'enable' => 'on' ] ] ),
    'loop-flat'        => static fn() => $okLoop( [ 'enable' => 'on', 'queryType' => 'post_types' ] ),
    'loop-list'        => static fn() => $okLoop( [] ),
    'value-string'     => static fn() => $okLoop( [ 'desktop' => [ 'value' => 'on' ] ] ),
    'include-string'   => static fn() => $ok( $posts( [ 'includeSpecificPosts' => '1' ] ) ),
    'meta-string'      => static fn() => $ok( $posts( [ 'metaQuery' => 'x' ] ) ),
    'terms-string'     => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => 'category' ] ) ),
    'token-unwrapped'  => static fn() => $wrap( $group( $wrapV( $posts() ), $block( 'heading', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => '$variable({"type":"content","value":{"name":"loop_post_title","settings":{}}})' ] ] ], 'builderVersion' => '5.15.1' ] ) ) ),
    // ---- second batch: type confusion on every key, context ----
    'order-array'      => static fn() => $ok( $posts( [ 'order' => [ 'descending' ] ] ) ),
    'order-true'       => static fn() => $ok( $posts( [ 'order' => true ] ) ),
    'orderby-int'      => static fn() => $ok( $posts( [ 'orderBy' => 5 ] ) ),
    'offset-text'      => static fn() => $ok( $posts( [ 'postOffset' => 'x' ] ) ),
    'offset-array'     => static fn() => $ok( $posts( [ 'postOffset' => [ 1 ] ] ) ),
    'ppp-array'        => static fn() => $ok( $posts( [ 'postPerPage' => [ 3 ] ] ) ),
    'querytype-array'  => static fn() => $ok( $posts( [ 'queryType' => [ 'post_types' ] ] ) ),
    'enable-array'     => static fn() => $ok( $posts( [ 'enable' => [ 'on' ] ] ) ),
    'loopid-array'     => static fn() => $ok( $posts( [ 'loopId' => [ 'x' ] ] ) ),
    'subtypes-valarr'  => static fn() => $ok( $posts( [ 'subTypes' => [ [ 'value' => [ 'post' ], 'label' => 'Posts' ] ] ] ) ),
    'subtypes-valint'  => static fn() => $ok( $posts( [ 'subTypes' => [ [ 'value' => 5, 'label' => 'Posts' ] ] ] ) ),
    'subtypes-two'     => static fn() => $ok( $posts( [ 'subTypes' => [ [ 'value' => 'post', 'label' => 'Posts' ], [ 'value' => 'page', 'label' => 'Pages' ] ] ] ) ),
    'include-object'   => static fn() => $ok( $posts( [ 'includeSpecificPosts' => [ 'value' => '1' ] ] ) ),
    'include-items-str' => static fn() => $ok( $posts( [ 'includeSpecificPosts' => [ '1', '2' ] ] ) ),
    'exclude-items-arr' => static fn() => $ok( $posts( [ 'excludeSpecificPosts' => [ [ [ 1 ] ] ] ] ) ),
    'meta-items-str'   => static fn() => $ok( $posts( [ 'metaQuery' => [ 'x' ] ] ) ),
    'meta-item-empty'  => static fn() => $ok( $posts( [ 'metaQuery' => [ [] ] ] ) ),
    'meta-object'      => static fn() => $ok( $posts( [ 'metaQuery' => [ 'metaKey' => 'x' ] ] ) ),
    'meta-compare-bad' => static fn() => $ok( $posts( [ 'metaQuery' => [ [ 'id' => 'a', 'metaKey' => 'k', 'metaValue' => 'v', 'compare' => 'LIKE%', 'type' => 'CHAR' ] ] ] ) ),
    'terms-items-str'  => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => [ 'category' ] ] ) ),
    'terms-sel-str'    => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => [ [ 'categoryId' => 'category', 'categoryName' => 'C', 'selectedOptions' => 'x' ] ] ] ) ),
    'terms-id-arr'     => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => [ [ 'categoryId' => [ 'c' ], 'categoryName' => 'C', 'selectedOptions' => [] ] ] ] ) ),
    'exclude-cur-true' => static fn() => $ok( $posts( [ 'excludeCurrentPost' => true ] ) ),
    'sticky-array'     => static fn() => $ok( $posts( [ 'ignoreStickysPost' => [ 'on' ] ] ) ),
    'search-array'     => static fn() => $ok( $posts( [ 'search' => [ 'x' ] ] ) ),
    'search-text'      => static fn() => $ok( $posts( [ 'search' => 'Small' ] ) ),
    'token-outside'    => static fn() => $wrap( $heading( 'loop_post_title' ) ),
    'nested-loops'     => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) . $group( $wrapV( $posts( [ 'loopId' => 'loop-spike0002', 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) ) ),
    'text-body'        => static fn() => $wrap( $group( $wrapV( $posts() ), $block( 'text', [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<h3>' . $tok( 'loop_post_title' ) . '</h3><p>' . $tok( 'loop_post_excerpt' ) . '</p>' ] ] ], 'builderVersion' => '5.15.1' ] ) ) ),
    'loop-on-col'      => static fn() => $block( 'section', [ 'builderVersion' => '5.15.1' ], $block( 'row', [ 'builderVersion' => '5.15.1' ], $block( 'column', $withLoop( $wrapV( $posts() ), [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ] ] ), $heading( 'loop_post_title' ) ) ) ),
    'meta-empty-str'   => static fn() => $ok( $posts( [ 'metaQuery' => '' ] ) ),
    'meta-empty-arr'   => static fn() => $ok( $posts( [ 'metaQuery' => [] ] ) ),
    'order-empty-str'  => static fn() => $ok( $posts( [ 'order' => '' ] ) ),
    'enable-off'       => static fn() => $ok( $posts( [ 'enable' => 'off', 'loopId' => '' ] ) ),
    'token-unknown'    => static fn() => $wrap( $group( $wrapV( $posts() ), $heading( 'loop_nope' ) ) ),
];

$which = $args[0] ?? '';
if ( 'list' === $which ) {
    WP_CLI::log( implode( ' ', array_keys( $cases ) ) );

    return;
}
if ( ! isset( $cases[ $which ] ) ) {
    WP_CLI::error( 'unknown case ' . $which );
}

$content = ( $cases[ $which ] )();
$gate    = LayoutGate::check( $content );
$verdict = $gate->isValid() ? 'accepts' : 'REFUSES ' . wp_json_encode( array_slice( array_map( static fn( $v ) => is_object( $v ) && method_exists( $v, 'toArray' ) ? $v->toArray() : $v, $gate->violations() ), 0, 2 ) );
$pid     = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied spike loop ' . $which, 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
if ( is_wp_error( $pid ) ) {
    WP_CLI::error( 'save failed: ' . $pid->get_error_message() );
}
register_shutdown_function( static function () use ( $pid ): void {
    if ( null !== get_post( $pid ) ) {
        wp_delete_post( $pid, true );
    }
} );
$stored  = (string) get_post( $pid )->post_content;
$notices = [];
set_error_handler( static function ( int $no, string $str ) use ( &$notices ): bool {
    $notices[] = substr( $str, 0, 160 );

    return true;
} );
$html  = '';
$threw = '';
try {
    query_posts( [ 'p' => $pid, 'post_type' => 'page', 'post_status' => 'any' ] );
    if ( have_posts() ) {
        the_post();
    }
    $html = (string) apply_filters( 'the_content', $stored );
} catch ( Throwable $e ) {
    $threw = get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 140 );
} finally {
    wp_reset_query();
    restore_error_handler();
}
$heads = preg_match_all( '#<h\d[^>]*>(.*?)</h\d>#s', $html, $m ) ? array_map( static fn( $s ) => trim( wp_strip_all_tags( $s ) ), $m[1] ) : [];
WP_CLI::log( sprintf(
    "%-20s validator %s | stored=sent:%s | threw:%s | notices:%d%s | groups:%d | headings:%d %s | imgs:%d | links:%d",
    $which,
    $verdict,
    $stored === $content ? 'yes' : 'no',
    '' === $threw ? '-' : $threw,
    count( array_unique( $notices ) ),
    [] === $notices ? '' : ' ' . wp_json_encode( array_slice( array_values( array_unique( $notices ) ), 0, 1 ) ),
    substr_count( $html, 'et_pb_group ' ),
    count( $heads ),
    wp_json_encode( array_slice( $heads, 0, 4 ) ),
    substr_count( $html, '<img' ),
    substr_count( $html, 'et_pb_button' )
) );
