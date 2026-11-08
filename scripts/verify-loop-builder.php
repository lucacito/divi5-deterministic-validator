<?php
/**
 * Real-site check for the Loop Builder (module.advanced.loop): the evidence gate for the validator's INVALID_LOOP_SETTING and
 * MALFORMED_DYNAMIC_TOKEN rules and for what the guide teaches.
 *
 * It registers a throwaway post type `manufacturer` with a taxonomy `state` and a `logo` custom field, creates six posts in two
 * terms, saves the builder's own export (fixtures/valid/loop-builder-5.3.json, the term ids swapped for the new ones) and the
 * guide's posts/products/terms/users loops as throwaway draft pages, renders them with the REAL Divi and checks what comes out:
 * one item per matching post, in the asked order, with the custom-field image and the permalink. Then each shape the validator
 * refuses is saved and rendered too, to show why: the page throws, or the loop renders nothing / the wrong thing.
 * Everything it creates is deleted again, also on an abort.
 *
 * Run: make verify-loop-builder   (needs `make up` on Divi 5.15 or newer; dev site only)
 */

use AiEditorDivi5\WP\LayoutGate;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( LayoutGate::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

global $wpdb;
$leftovers = static function () use ( $wpdb ): int {
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify loop%'" ); // phpcs:ignore
};
if ( $leftovers() > 0 ) {
    WP_CLI::error( "something titled 'aied verify loop...' is already stored (an earlier run was killed?): delete it first" );
}

register_post_type( 'manufacturer', [ 'public' => true, 'label' => 'Manufacturers', 'has_archive' => false ] );
register_taxonomy( 'state', 'manufacturer', [ 'public' => true, 'label' => 'States', 'hierarchical' => true ] );

$ids  = [ 'pages' => [], 'posts' => [], 'terms' => [] ];
$wipe = static function () use ( &$ids ): void {
    foreach ( $ids['pages'] as $id ) {
        if ( null !== get_post( $id ) ) {
            wp_delete_post( $id, true );
        }
    }
    foreach ( $ids['posts'] as $id ) {
        if ( null !== get_post( $id ) ) {
            wp_delete_post( $id, true );
        }
    }
    foreach ( $ids['terms'] as $id ) {
        wp_delete_term( $id, 'state' );
    }
};
register_shutdown_function( $wipe );

$render = static function ( int $id ): array {
    $post    = get_post( $id );
    $notices = [];
    set_error_handler( static function ( int $no, string $str ) use ( &$notices ): bool {
        $notices[] = substr( $str, 0, 160 );

        return true;
    } );
    $html  = '';
    $threw = '';
    try {
        query_posts( [ 'p' => $id, 'post_type' => 'page', 'post_status' => 'any' ] );
        if ( have_posts() ) {
            the_post();
        }
        $html = (string) apply_filters( 'the_content', $post->post_content );
    } catch ( Throwable $e ) {
        $threw = get_class( $e );
    } finally {
        wp_reset_query();
        restore_error_handler();
    }

    return [ 'html' => $html, 'threw' => $threw, 'notices' => $notices ];
};
$save = static function ( string $content, string $name ) use ( &$ids ): int {
    $pid = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied verify loop ' . $name, 'post_content' => wp_slash( $content ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $pid ) ) {
        WP_CLI::error( 'could not save ' . $name . ': ' . $pid->get_error_message() );
    }
    $ids['pages'][] = (int) $pid;

    return (int) $pid;
};
$tok = static fn( string $name, array $settings = [] ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => $name, 'settings' => (object) $settings ] ] ) . ')$';
$blk = static function ( string $name, array $attrs, ?string $inner = null ): string {
    $json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );

    return null === $inner ? "<!-- wp:divi/{$name} {$json} /-->\n" : "<!-- wp:divi/{$name} {$json} -->\n{$inner}<!-- /wp:divi/{$name} -->\n";
};
$wrap = static function ( string $inner ) use ( $blk ): string {
    $col = $blk( 'column', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ], 'builderVersion' => '5.8.0' ], $inner );

    return $blk( 'section', [ 'builderVersion' => '5.8.0' ], $blk( 'row', [ 'builderVersion' => '5.8.0' ], $col ) );
};
$heading = static fn( string $var ) => $blk( 'heading', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $tok( $var ) ] ] ], 'builderVersion' => '5.8.0' ] );
$loopOn  = static fn( array $over = [] ): array => array_replace( [ 'enable' => 'on', 'loopId' => 'loop-verify0001', 'queryType' => 'post_types', 'subTypes' => [ [ 'value' => 'post', 'label' => 'Posts' ] ], 'orderBy' => 'date', 'order' => 'descending', 'postPerPage' => '3', 'postOffset' => '0' ], $over );
$group   = static fn( array $loop, string $inner ) => $blk( 'group', [ 'module' => [ 'advanced' => [ 'loop' => [ 'desktop' => [ 'value' => $loop ] ] ] ], 'builderVersion' => '5.8.0' ], $inner );
$headings = static fn( string $html ): array => preg_match_all( '#<h\d[^>]*>(.*?)</h\d>#s', $html, $m ) ? array_map( static fn( $s ) => trim( wp_strip_all_tags( $s ) ), $m[1] ) : [];

try {
    // ---- a small catalogue: three states, six manufacturers, each with a logo field ----------------------------------------
    $terms = [];
    foreach ( [ 'North Carolina', 'Louisiana', 'Florida' ] as $name ) {
        $t = wp_insert_term( 'aied verify loop ' . $name, 'state' );
        $ids['terms'][] = (int) $t['term_id'];
        $terms[ $name ] = (int) $t['term_id'];
    }
    $made = [];
    foreach ( [ 'Zeta Ovens' => 'North Carolina', 'Alpha Fryers' => 'North Carolina', 'Mid Grills' => 'North Carolina', 'Bayou Boilers' => 'Louisiana', 'Gulf Mixers' => 'Louisiana', 'Palm Slicers' => 'Florida' ] as $title => $state ) {
        $pid = wp_insert_post( [ 'post_type' => 'manufacturer', 'post_status' => 'publish', 'post_title' => 'aied verify loop ' . $title, 'meta_input' => [ 'logo' => 'https://example.test/logo-' . sanitize_title( $title ) . '.png' ] ] );
        wp_set_object_terms( (int) $pid, [ $terms[ $state ] ], 'state' );
        $ids['posts'][]       = (int) $pid;
        $made[ $state ][ $title ] = (int) $pid;
    }
    $check( 6 === count( $ids['posts'] ) && 3 === count( $terms ), 'the throwaway catalogue exists (3 states, 6 manufacturers with a logo field)' );

    // ---- the builder's own export, rendered ---------------------------------------------------------------------------------
    $fx = json_decode( (string) file_get_contents( '/fixtures/valid/loop-builder-5.3.json' ), true );
    $c  = (string) ( $fx['post_content'] ?? '' );
    $c  = str_replace( [ '\\"value\\":\\"3\\",\\"label\\":\\"North Carolina\\"', '"value":"3","label":"North Carolina"' ], [ '\\"value\\":\\"' . $terms['North Carolina'] . '\\",\\"label\\":\\"North Carolina\\"', '"value":"' . $terms['North Carolina'] . '","label":"North Carolina"' ], $c );
    $c  = str_replace( [ '\\"value\\":\\"10\\",\\"label\\":\\"Louisiana\\"', '"value":"10","label":"Louisiana"' ], [ '\\"value\\":\\"' . $terms['Louisiana'] . '\\",\\"label\\":\\"Louisiana\\"', '"value":"' . $terms['Louisiana'] . '","label":"Louisiana"' ], $c );
    $check( LayoutGate::check( $c )->isValid(), 'the validator accepts the builder\'s own loop export' );
    $pid    = $save( $c, 'builder export' );
    $stored = (string) get_post( $pid )->post_content;
    $check( LayoutGate::check( $stored )->isValid(), 'and the STORED content still passes' );
    $r = $render( $pid );
    $check( '' === $r['threw'], 'Divi renders the builder export without throwing' . ( '' !== $r['threw'] ? ' (' . $r['threw'] . ')' : '' ) );
    preg_match_all( '#<img[^>]+src="([^"]+)"#', $r['html'], $imgs );
    $srcs = $imgs[1] ?? [];
    $want = [];
    foreach ( [ 'Alpha Fryers', 'Mid Grills', 'Zeta Ovens' ] as $t ) { // name ascending, North Carolina
        $want[] = 'https://example.test/logo-' . sanitize_title( $t ) . '.png';
    }
    foreach ( [ 'Bayou Boilers', 'Gulf Mixers' ] as $t ) { // the Louisiana loop
        $want[] = 'https://example.test/logo-' . sanitize_title( $t ) . '.png';
    }
    $check( $srcs === $want, 'the looped images are the custom-field logos, one per manufacturer of the term, in name order (' . count( $srcs ) . ' images)' );
    $check( 3 + 2 === count( $srcs ) && 0 === count( array_diff( [ get_permalink( $made['North Carolina']['Alpha Fryers'] ) ], array_map( 'html_entity_decode', preg_match_all( '#<a[^>]+href="([^"]+)"#', $r['html'], $a ) ? $a[1] : [] ) ) ), 'each image links to its manufacturer\'s own page (the loop_post_link token)' );

    // ---- the shapes the guide teaches ---------------------------------------------------------------------------------------
    $cases = [
        'posts, newest first'    => [ $group( $loopOn(), $heading( 'loop_post_title' ) ), 3 ],
        'products'               => [ $group( $loopOn( [ 'subTypes' => [ [ 'value' => 'product', 'label' => 'Products' ] ] ] ), $heading( 'loop_product_title' ) ), 3 ],
        'terms'                  => [ $group( $loopOn( [ 'queryType' => 'post_taxonomies', 'subTypes' => [ [ 'value' => 'state', 'label' => 'States' ] ], 'orderBy' => 'name', 'order' => 'ascending' ] ), $heading( 'loop_term_name' ) ), 3 ],
        'manufacturer, one state' => [ $group( $loopOn( [ 'subTypes' => [ [ 'value' => 'manufacturer', 'label' => 'Manufacturers' ] ], 'includePostWithSpecificTerms' => [ [ 'categoryId' => 'state', 'categoryName' => 'States', 'selectedOptions' => [ [ 'value' => (string) $terms['Louisiana'], 'label' => 'Louisiana' ] ] ] ], 'orderBy' => 'name', 'order' => 'ascending' ] ), $heading( 'loop_post_title' ) ), 2 ],
        'a row repeats'          => [ $blk( 'section', [ 'builderVersion' => '5.8.0' ], $blk( 'row', [ 'module' => [ 'advanced' => [ 'loop' => [ 'desktop' => [ 'value' => $loopOn() ] ] ] ], 'builderVersion' => '5.8.0' ], $blk( 'column', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '4_4' ] ] ] ], 'builderVersion' => '5.8.0' ], $heading( 'loop_post_title' ) ) ) ), 3 ],
    ];
    foreach ( $cases as $label => [ $inner, $count ] ) {
        $content = false !== strpos( $inner, 'wp:divi/section' ) ? $inner : $wrap( $inner );
        $check( LayoutGate::check( $content )->isValid(), "{$label}: the validator accepts it" );
        $r = $render( $save( $content, $label ) );
        $h = $headings( $r['html'] );
        if ( 'posts, newest first' === $label ) {
            $baseline = $h;
        }
        $check( '' === $r['threw'] && $count === count( $h ) && '' === implode( '', array_filter( $h, static fn( $x ) => '' === $x ) ), "{$label}: {$count} items repeat with their own text (" . count( $h ) . ' found' . ( '' !== $r['threw'] ? ', threw ' . $r['threw'] : '' ) . ')' );
    }
    // users, and a card that holds a text module and a button (title, excerpt, link)
    $users = $render( $save( $wrap( $group( $loopOn( [ 'queryType' => 'user_roles', 'subTypes' => [ [ 'value' => 'administrator', 'label' => 'Administrator' ] ], 'orderBy' => 'display_name', 'order' => 'ascending' ] ), $heading( 'loop_user_name' ) ) ), 'users' ) );
    $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ids' ] );
    $check( count( $admins ) === count( array_filter( $headings( $users['html'] ) ) ) && '' === $users['threw'], 'a users loop repeats once per administrator (' . count( $admins ) . ')' );
    $card = $blk( 'text', [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<h3>' . $tok( 'loop_post_title' ) . '</h3><p>' . $tok( 'loop_post_excerpt' ) . '</p>' ] ] ], 'builderVersion' => '5.8.0' ] )
        . $blk( 'button', [ 'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => [ 'text' => 'Read more', 'linkUrl' => $tok( 'loop_post_link' ) ] ] ] ], 'builderVersion' => '5.8.0' ] );
    $content = $wrap( $group( $loopOn(), $card ) );
    $check( LayoutGate::check( $content )->isValid(), 'a card with a text module and a button: the validator accepts it' );
    $r     = $render( $save( $content, 'card' ) );
    $newest = get_posts( [ 'post_type' => 'post', 'numberposts' => 3, 'orderby' => 'date', 'order' => 'DESC' ] );
    preg_match_all( '#<a[^>]+class="[^"]*et_pb_button[^"]*"[^>]+href="([^"]+)"#', $r['html'], $btn );
    $hrefs = array_map( 'html_entity_decode', $btn[1] ?? [] );
    $check( [] !== $newest && array_map( 'get_permalink', $newest ) === $hrefs, 'each button links to its own post (loop_post_link in a button; ' . count( $hrefs ) . ' buttons)' );
    $check( count( $newest ) === count( $headings( $r['html'] ) ) && $headings( $r['html'] ) === array_map( static fn( $p ) => $p->post_title, $newest ), 'the text module repeats the post titles in its h3, newest first' );
    $r = $render( $save( $wrap( $group( $loopOn( [ 'subTypes' => [ [ 'value' => 'manufacturer', 'label' => 'M' ] ], 'orderBy' => 'name', 'order' => 'ascending', 'postPerPage' => '6' ] ), $heading( 'loop_post_title' ) ) ), 'name order' ) );
    $check( [ 'aied verify loop Alpha Fryers', 'aied verify loop Bayou Boilers', 'aied verify loop Gulf Mixers', 'aied verify loop Mid Grills', 'aied verify loop Palm Slicers', 'aied verify loop Zeta Ovens' ] === $headings( $r['html'] ), 'orderBy name + order ascending sorts the items by name' );

    // ---- what the validator refuses, and why --------------------------------------------------------------------------------
    $refused = [
        'metaQuery as a string'  => [ $loopOn( [ 'metaQuery' => '' ] ), 'throws' ],
        'order as an array'      => [ $loopOn( [ 'order' => [ 'descending' ] ] ), 'throws' ],
        'loopId as an array'     => [ $loopOn( [ 'loopId' => [ 'x' ] ] ), 'throws' ],
        'enable true'            => [ $loopOn( [ 'enable' => true ] ), 'off' ],
        'enable yes'             => [ $loopOn( [ 'enable' => 'yes' ] ), 'off' ],
        'queryType unknown'      => [ $loopOn( [ 'queryType' => 'products' ] ), 'empty' ],
        'subTypes as a string list' => [ $loopOn( [ 'subTypes' => [ 'post' ] ] ), 'everything' ],
    ];
    foreach ( $refused as $label => [ $loop, $effect ] ) {
        $content = $wrap( $group( $loop, $heading( 'loop_post_title' ) ) );
        $gate    = LayoutGate::check( $content );
        $check( ! $gate->isValid(), "{$label}: the validator refuses it" );
        $r = $render( $save( $content, $label ) );
        $h = $headings( $r['html'] );
        if ( 'throws' === $effect ) {
            $check( '' !== $r['threw'], "{$label}: Divi really throws (" . $r['threw'] . ')' );
        } elseif ( 'off' === $effect || 'empty' === $effect ) {
            $check( '' === $r['threw'] && 0 === count( array_filter( $h ) ), "{$label}: Divi renders no item (loop silently off)" );
        } else {
            $check( '' === $r['threw'] && 3 === count( $h ) && $h !== $baseline, "{$label}: Divi renders the WRONG items (the loop read every post type, so other items come up: " . implode( ', ', array_slice( $h, 0, 2 ) ) . ')' );
        }
    }
    $bad = $wrap( $group( $loopOn(), $blk( 'heading', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => '$variable({"type":"content","value":{"name":"loop_post_title","settings":{}}})' ] ] ], 'builderVersion' => '5.8.0' ] ) ) );
    $check( ! LayoutGate::check( $bad )->isValid(), 'a token without its closing )$: the validator refuses it' );
    $r = $render( $save( $bad, 'open token' ) );
    $check( false !== strpos( implode( '|', $headings( $r['html'] ) ), '$variable(' ), 'and Divi really prints the raw "$variable(" text on the page' );
} finally {
    $wipe();
}

$check( 0 === $leftovers(), 'every throwaway page, manufacturer and state was deleted (0 left)' );
if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Loop Builder: the builder\'s export and the guide\'s loops validate and render; every refused shape is shown to break the page or the loop.' );
