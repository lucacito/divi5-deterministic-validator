<?php
/**
 * Probe: Loop Builder sources the sweep did not cover on Divi 5.15.1: pagination (post-nav + ?loopId=N), menus, current_page.
 * Reads: LoopUtils.php 722-840 (page from $_GET[loopId]), PostNavigationModule.php 697-1016 (module.advanced.targetLoop).
 *
 * Shapes come from Divi's own sources (LoopUtils.php reads, ai-agent.js documents `module.advanced.loop.desktop.value`).
 * Saves ONE case as a throwaway draft page on the dev site, renders it and prints what came out; asserts nothing.
 * One case per process, because a render-fatal PHP error ends the process.
 * Every page it creates is titled 'aied spike loop ...' and deleted again.
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/probe-loop-sources.php <case|list> --user=1
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


$menuId = (string) ( wp_get_nav_menus()[0]->term_id ?? '' );
$nav = static function ( string $loopId ) use ( $block ): string {
    return $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'desktop' => [ 'value' => $loopId ] ] ] ], 'builderVersion' => '5.15.1' ] );
};
$withNav = static function ( array $v, string $var = 'loop_post_title' ) use ( $group, $wrap, $heading, $nav, $wrapV ): string {
    return $wrap( $group( $wrapV( $v ), $heading( $var ) ) . $nav( $v['loopId'] ?? 'loop-spike0001' ) );
};
$menuLoop = static function ( array $over = [] ): array {
    return array_replace( [ 'enable' => 'on', 'loopId' => 'loop-spikemenu1', 'queryType' => 'menus', 'subTypes' => [], 'orderBy' => 'menu_order', 'order' => 'ascending', 'postPerPage' => '5' ], $over );
};
$cases = [
    'pg-p1'        => static fn() => $withNav( $posts( [ 'postPerPage' => '2' ] ) ),
    'pg-p2'        => static fn() => $withNav( $posts( [ 'postPerPage' => '2' ] ) ),
    'pg-p3'        => static fn() => $withNav( $posts( [ 'postPerPage' => '2' ] ) ),
    'pg-p99'       => static fn() => $withNav( $posts( [ 'postPerPage' => '2' ] ) ),
    'pg-pneg'      => static fn() => $withNav( $posts( [ 'postPerPage' => '2' ] ) ),
    'pg-offset-p2' => static fn() => $withNav( $posts( [ 'postPerPage' => '2', 'postOffset' => '1' ] ) ),
    'pg-nonav'     => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) ),
    'pg-badtarget' => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $nav( 'loop-nothere01' ) ),
    'pg-targetarr' => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'desktop' => [ 'value' => [ 'loop-spike0001' ] ] ] ] ], 'builderVersion' => '5.15.1' ] ) ),
    'pg-targetstr' => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => 'loop-spike0001' ] ], 'builderVersion' => '5.15.1' ] ) ),
    'menu-ok'      => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'subTypes' => [ [ 'value' => $menuId, 'label' => 'primary menu' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-valint'  => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'subTypes' => [ [ 'value' => (int) $menuId, 'label' => 'primary menu' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-none'    => static fn() => $wrap( $group( $wrapV( $menuLoop() ), $heading( 'loop_menu_text' ) ) ),
    'menu-badid'   => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'subTypes' => [ [ 'value' => '99999', 'label' => 'x' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-ordpost' => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'orderBy' => 'date', 'subTypes' => [ [ 'value' => $menuId, 'label' => 'm' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-ppp1'    => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'postPerPage' => '1', 'subTypes' => [ [ 'value' => $menuId, 'label' => 'm' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-postok'  => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'subTypes' => [ [ 'value' => $menuId, 'label' => 'm' ] ] ] ) ), $heading( 'loop_post_title' ) ) ),
    'menu-desc'    => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'order' => 'descending', 'postPerPage' => '15', 'subTypes' => [ [ 'value' => $menuId, 'label' => 'm' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-asc15'   => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'order' => 'ascending', 'postPerPage' => '15', 'subTypes' => [ [ 'value' => $menuId, 'label' => 'm' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-title'   => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'orderBy' => 'title', 'order' => 'ascending', 'postPerPage' => '15', 'subTypes' => [ [ 'value' => $menuId, 'label' => 'm' ] ] ] ) ), $heading( 'loop_menu_text' ) ) ),
    'menu-link'    => static fn() => $wrap( $group( $wrapV( $menuLoop( [ 'order' => 'descending', 'postPerPage' => '3', 'subTypes' => [ [ 'value' => $menuId, 'label' => 'm' ] ] ] ) ), $heading( 'loop_menu_text' ) . $block( 'button', [ 'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => 'Go', 'linkUrl' => $tok( 'loop_menu_link' ) ] ] ] ], 'builderVersion' => '5.15.1' ] ) ) ),
    'pg-tgt-int'   => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'desktop' => [ 'value' => 5 ] ] ] ], 'builderVersion' => '5.15.1' ] ) ),
    'pg-tgt-true'  => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'desktop' => [ 'value' => true ] ] ] ], 'builderVersion' => '5.15.1' ] ) ),
    'pg-tgt-empty' => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'desktop' => [ 'value' => '' ] ] ] ], 'builderVersion' => '5.15.1' ] ) ),
    'pg-tgt-obj'   => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'desktop' => [ 'value' => [ 'a' => 'loop-spike0001' ] ] ] ] ], 'builderVersion' => '5.15.1' ] ) ),
    'pg-tgt-nodsk' => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $block( 'post-nav', [ 'module' => [ 'advanced' => [ 'targetLoop' => [ 'value' => 'loop-spike0001' ] ] ], 'builderVersion' => '5.15.1' ] ) ),
    'rep-noacf'    => static fn() => $wrap( $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spikerep01', 'queryType' => 'repeater_team', 'subTypes' => [], 'orderBy' => 'date', 'order' => 'descending', 'postPerPage' => '3' ] ), $heading( 'loop_post_title' ) ) ),
    'rep-badname'  => static fn() => $wrap( $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spikerep01', 'queryType' => 'repeater_', 'subTypes' => [], 'postPerPage' => '3' ] ), $heading( 'loop_post_title' ) ) ),
    'terms-alias'  => static fn() => $wrap( $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spiketerm1', 'queryType' => 'terms', 'subTypes' => [ [ 'value' => 'category', 'label' => 'Categories' ] ], 'orderBy' => 'name', 'order' => 'ascending', 'postPerPage' => '5' ] ), $heading( 'loop_term_name' ) ) ),
    'terms-tax'    => static fn() => $wrap( $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spiketerm1', 'queryType' => 'post_taxonomies', 'subTypes' => [ [ 'value' => 'category', 'label' => 'Categories' ] ], 'orderBy' => 'name', 'order' => 'ascending', 'postPerPage' => '5' ] ), $heading( 'loop_term_name' ) ) ),
    'nest-terms'   => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) . $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spikeinner', 'queryType' => 'post_taxonomies', 'subTypes' => [ [ 'value' => 'category', 'label' => 'Categories' ] ], 'orderBy' => 'name', 'order' => 'ascending', 'postPerPage' => '5' ] ), $heading( 'loop_term_name' ) ) ) ),
    'nest-outertok' => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spikeinner', 'queryType' => 'post_taxonomies', 'subTypes' => [ [ 'value' => 'category', 'label' => 'Categories' ] ], 'orderBy' => 'name', 'order' => 'ascending', 'postPerPage' => '5' ] ), $heading( 'loop_post_title' ) ) ) ),
    'nest-sameid'  => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) . $group( $wrapV( $posts( [ 'postPerPage' => '3' ] ) ), $heading( 'loop_post_title' ) ) ) ),
    'dup-id'       => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $group( $wrapV( $posts( [ 'postPerPage' => '3', 'orderBy' => 'title', 'order' => 'ascending' ] ) ), $heading( 'loop_post_title' ) ) ),
    'two-ids'      => static fn() => $wrap( $group( $wrapV( $posts( [ 'postPerPage' => '2' ] ) ), $heading( 'loop_post_title' ) ) . $group( $wrapV( $posts( [ 'loopId' => 'loop-spike0002', 'postPerPage' => '3', 'orderBy' => 'title', 'order' => 'ascending' ] ) ), $heading( 'loop_post_title' ) ) ),
    'sub-unknown'  => static fn() => $ok( $posts( [ 'subTypes' => [ [ 'value' => 'nosuchtype', 'label' => 'X' ] ] ] ) ),
    'sub-mixed'    => static fn() => $ok( $posts( [ 'subTypes' => [ [ 'value' => 'nosuchtype', 'label' => 'X' ], [ 'value' => 'post', 'label' => 'Posts' ] ] ] ) ),
    'term-gone'    => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => [ [ 'categoryId' => 'category', 'categoryName' => 'C', 'selectedOptions' => [ [ 'value' => '98765', 'label' => 'Gone' ] ] ] ] ] ) ),
    'term-exgone'  => static fn() => $ok( $posts( [ 'excludePostWithSpecificTerms' => [ [ 'categoryId' => 'category', 'categoryName' => 'C', 'selectedOptions' => [ [ 'value' => '98765', 'label' => 'Gone' ] ] ] ] ] ) ),
    'term-badtax'  => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => [ [ 'categoryId' => 'nosuchtax', 'categoryName' => 'C', 'selectedOptions' => [ [ 'value' => '1', 'label' => 'x' ] ] ] ] ] ) ),
    'term-slug'    => static fn() => $ok( $posts( [ 'includePostWithSpecificTerms' => [ [ 'categoryId' => 'category', 'categoryName' => 'C', 'selectedOptions' => [ [ 'value' => 'uncategorized', 'label' => 'x' ] ] ] ] ] ) ),
    'cp-archive'   => static fn() => $wrap( $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spikecurr1', 'queryType' => 'current_page', 'subTypes' => [], 'orderBy' => 'date', 'order' => 'descending', 'postPerPage' => '3' ] ), $heading( 'loop_post_title' ) ) ),
    'cp-page'      => static fn() => $wrap( $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spikecurr1', 'queryType' => 'current_page', 'subTypes' => [], 'orderBy' => 'date', 'order' => 'descending', 'postPerPage' => '3' ] ), $heading( 'loop_post_title' ) ) ),
    'cp-sub-post'  => static fn() => $wrap( $group( $wrapV( [ 'enable' => 'on', 'loopId' => 'loop-spikecurr1', 'queryType' => 'current_page', 'subTypes' => [ [ 'value' => 'post', 'label' => 'Posts' ] ], 'orderBy' => 'date', 'order' => 'descending', 'postPerPage' => '3' ] ), $heading( 'loop_post_title' ) ) ),
];
// How the main query / page parameter is set for each case: [ main-query args, $_GET ].
$setup = [
    'dup-id' => [ [], [ 'loop-spike0001' => '2' ] ],
    'pg-p1' => [ [], [ 'loop-spike0001' => '1' ] ], 'pg-p2' => [ [], [ 'loop-spike0001' => '2' ] ], 'pg-p3' => [ [], [ 'loop-spike0001' => '3' ] ],
    'pg-p99' => [ [], [ 'loop-spike0001' => '99' ] ], 'pg-pneg' => [ [], [ 'loop-spike0001' => '-4' ] ], 'pg-offset-p2' => [ [], [ 'loop-spike0001' => '2' ] ],
    'cp-archive' => [ [ 'category_name' => 'uncategorized', 'post_type' => 'post', 'post_status' => 'publish' ], [] ],
    'cp-sub-post' => [ [ 'category_name' => 'uncategorized', 'post_type' => 'post', 'post_status' => 'publish' ], [] ],
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
    foreach ( ( $setup[ $which ][1] ?? [] ) as $k => $v ) {
        $_GET[ $k ] = $v;
    }
    query_posts( ( $setup[ $which ][0] ?? [] ) ?: [ 'p' => $pid, 'post_type' => 'page', 'post_status' => 'any' ] );
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
$navLinks = preg_match_all( '#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#s', (string) ( preg_match( '#et_pb_posts_nav.*#s', $html, $nm ) ? $nm[0] : '' ), $am ) ? array_map( static fn( $h, $t ) => $h . ' [' . trim( wp_strip_all_tags( $t ) ) . ']', $am[1], $am[2] ) : [];
WP_CLI::log( 'nav: ' . wp_json_encode( $navLinks ) . ' | has_nav:' . ( str_contains( $html, 'et_pb_posts_nav' ) ? 'yes' : 'no' ) );
WP_CLI::log( 'button hrefs: ' . wp_json_encode( preg_match_all( '#<a[^>]+class="[^"]*et_pb_button[^"]*"[^>]*href="([^"]*)"|<a[^>]+href="([^"]*)"[^>]*class="[^"]*et_pb_button#', $html, $hm ) ? array_values( array_filter( array_merge( $hm[1], $hm[2] ) ) ) : [] ) );
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

