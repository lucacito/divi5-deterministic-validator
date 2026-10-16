<?php
/**
 * Real-site check for Divi 5.15 Components: the evidence gate for the validator rules that accept a component instance
 * (a section, a row or a module saved as a component), a Slot (empty or filled with local modules) and a nested component.
 *
 * The fixtures fixtures/valid/component-*-5.15.json are page contents the REAL 5.15.1 builder saved. Each one points at a
 * Divi Library item (a global component) by id, so this script first recreates those four library items on a throwaway
 * basis from fixtures/components/library-5.15.json (the items exactly as the builder stored them), points every fixture's
 * globalModule at the new ids, saves each as a throwaway page and renders it with the real Divi. It proves, per fixture:
 * the validator accepts it, the STORED content (after the save) still passes, Divi renders it without errors or notices,
 * and the words that must show (the component's own text, a slot's local modules) are on the rendered page.
 * Everything it creates is deleted again, also on an abort.
 *
 * Run: make verify-components   (needs `make up` on Divi 5.15 or newer; dev site only)
 */

use AiEditorDivi5\WP\LayoutGate;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( LayoutGate::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}
if ( ! class_exists( '\ET\Builder\Packages\GlobalLayout\GlobalLayout' ) ) {
    WP_CLI::error( 'This Divi has no Components (5.15 or newer needed).' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

$library = json_decode( (string) file_get_contents( '/fixtures/components/library-5.15.json' ), true );
if ( ! is_array( $library ) || ! isset( $library['templates'] ) ) {
    WP_CLI::error( 'fixtures/components/library-5.15.json is not mounted at /fixtures/components (see docker-compose.yml).' );
}

// What must be on the rendered page, per fixture.
$expect = [
    'component-instance-plain'        => [ 'Component probe', 'Some body text' ],
    'component-instance-slot-empty'   => [ 'Component probe', 'Some body text' ],
    'component-instance-slot-filled'  => [ 'Component probe', 'Some body text', 'Slot Button', 'et_pb_contact_form', 'Email Address' ],
    'component-instance-row'          => [ 'Row component probe', 'Row body' ],
    'component-instance-module'       => [ 'Module component probe', 'Module body' ],
    'component-nested'                => [ 'Module component probe', 'Module body' ],
];

$libraryIds = [];
$pageIds    = [];
$cleanup    = static function () use ( &$libraryIds, &$pageIds ): void {
    foreach ( $pageIds as $id ) {
        if ( null !== get_post( $id ) ) {
            wp_delete_post( $id, true );
        }
    }
    foreach ( $libraryIds as $id ) {
        if ( null !== get_post( $id ) ) {
            wp_delete_post( $id, true );
        }
    }
};
$leftovers = static function (): int {
    global $wpdb;

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify components%'" ); // phpcs:ignore
};
if ( $leftovers() > 0 ) {
    WP_CLI::error( "something titled 'aied verify components...' is already stored (an earlier run was killed?): delete it first" );
}

$render = static function ( int $id ): array {
    $post    = get_post( $id );
    $notices = [];
    set_error_handler( static function ( int $no, string $str ) use ( &$notices ): bool {
        $notices[] = substr( $str, 0, 160 );

        return true;
    } );
    $html = '';
    $err  = null;
    try {
        query_posts( [ 'p' => $id, 'post_type' => 'page', 'post_status' => 'any' ] );
        if ( have_posts() ) {
            the_post();
        }
        $html = (string) apply_filters( 'the_content', $post->post_content );
    } catch ( Throwable $e ) {
        $err = get_class( $e ) . ': ' . $e->getMessage();
    } finally {
        wp_reset_query();
        restore_error_handler();
    }

    return [ 'html' => $html, 'notices' => array_values( array_unique( $notices ) ), 'error' => $err ];
};

try {
    // ---- the four library items (global components), recreated exactly as the builder stored them ------------------------
    $idMap = [];
    foreach ( $library['templates'] as $key => $t ) {
        $new = wp_insert_post( [ 'post_type' => 'et_pb_layout', 'post_status' => 'publish', 'post_title' => 'aied verify components ' . $key, 'post_content' => wp_slash( (string) $t['post_content'] ) ], true );
        if ( is_wp_error( $new ) ) {
            WP_CLI::error( 'could not create the library item ' . $key . ': ' . $new->get_error_message() );
        }
        $libraryIds[]      = (int) $new;
        $idMap[ $t['id'] ] = (int) $new;
        wp_set_object_terms( (int) $new, $t['scope'], 'scope' );
        wp_set_object_terms( (int) $new, $t['layout_type'], 'layout_type' );
        foreach ( $t['meta'] as $mk => $mv ) {
            update_post_meta( (int) $new, $mk, $mv );
        }
    }
    foreach ( $library['templates'] as $key => $t ) { // a component that cites another component (the nested one)
        $c = (string) get_post( $idMap[ $t['id'] ] )->post_content;
        foreach ( $idMap as $old => $newId ) {
            $c = str_replace( '"globalModule":"' . $old . '"', '"globalModule":"' . $newId . '"', $c );
        }
        wp_update_post( [ 'ID' => $idMap[ $t['id'] ], 'post_content' => wp_slash( $c ) ] );
    }
    $check( 4 === count( $libraryIds ) && \ET\Builder\Packages\GlobalLayout\GlobalLayout::is_global_layout_template( $idMap[ $library['templates']['section-with-slot']['id'] ] ), 'the four library items exist and Divi sees them as global components' );

    // ---- every fixture ------------------------------------------------------------------------------------------------
    foreach ( $expect as $name => $words ) {
        $fx = json_decode( (string) file_get_contents( "/fixtures/valid/{$name}-5.15.json" ), true );
        $c  = (string) ( $fx['post_content'] ?? '' );
        foreach ( $idMap as $old => $newId ) {
            $c = str_replace( '"globalModule":"' . $old . '"', '"globalModule":"' . $newId . '"', $c );
        }
        $check( LayoutGate::check( $c )->isValid(), "{$name}: the validator accepts the builder's own content" );

        $pid = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied verify components ' . $name, 'post_content' => wp_slash( $c ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
        if ( is_wp_error( $pid ) ) {
            $check( false, "{$name}: could not save the page: " . $pid->get_error_message() );
            continue;
        }
        $pageIds[] = (int) $pid;
        $stored    = (string) get_post( $pid )->post_content;
        $check( LayoutGate::check( $stored )->isValid(), "{$name}: the STORED content passes the validator" );
        $check( 0 === preg_match( '/(?<!\\\\)u003c/', $stored ), "{$name}: no corrupted \"u003c\" (a stored \\u003c keeps its backslash; the builder writes them itself)" );

        $r = $render( (int) $pid );
        $check( null === $r['error'] && [] === $r['notices'], "{$name}: Divi renders it without errors or notices" . ( null !== $r['error'] ? ' (' . $r['error'] . ')' : '' ) . ( [] !== $r['notices'] ? ' (' . implode( ' | ', $r['notices'] ) . ')' : '' ) );
        $plain = html_entity_decode( wp_strip_all_tags( $r['html'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        foreach ( $words as $w ) {
            $hay = 0 === strpos( $w, 'et_pb_' ) ? $r['html'] : $plain;
            $check( false !== strpos( $hay, $w ), "{$name}: the rendered page shows '{$w}'" );
        }
        $check( false === strpos( $r['html'], 'wp:divi/' ), "{$name}: no raw block comments leak into the page" );
        $check( 1 === substr_count( $plain, $words[0] ) || 'component-instance-slot-filled' === $name, "{$name}: '{$words[0]}' is shown once, not duplicated" );
    }

    // ---- what the AI is told about components: get_site_structure and the advisory, on the real Divi --------------------------
    $sectionId = $idMap[ $library['templates']['section-with-slot']['id'] ];
    $found     = [];
    $disc      = \AiEditorDivi5\WP\ComponentReader::discover();
    foreach ( (array) ( $disc['components'] ?? [] ) as $row ) {
        $found[ $row['id'] ] = $row;
    }
    $check( null !== $disc && 4 === count( array_intersect_key( $found, array_flip( $libraryIds ) ) ), 'get_site_structure lists the four components (components section reader)' );
    $check( 'section' === ( $found[ $sectionId ]['kind'] ?? '' ) && 1 === count( $found[ $sectionId ]['slots'] ?? [] ), 'the section component is listed as a section with its one Slot id' );
    $check( 3 === (int) ( $found[ $sectionId ]['used_on'] ?? -1 ), 'and as used on 3 pages (plain, slot-empty and slot-filled)' );
    $check( 'row' === ( $found[ $idMap[ $library['templates']['row']['id'] ] ]['kind'] ?? '' ) && 'module' === ( $found[ $idMap[ $library['templates']['module']['id'] ] ]['kind'] ?? '' ), 'the row and module components carry their kinds' );
    $check( false === strpos( (string) json_encode( \AiEditorDivi5\WP\SiteStructure::catalog( [ 'components' ] )['components'] ), 'wp:divi/' ), 'the section never returns a component\'s content' );

    $plainPage = (int) $pageIds[0];
    $before    = (string) get_post( $plainPage )->post_content;
    $after     = str_replace( 'Component probe', 'A better headline', $before );
    $adv       = \AiEditorDivi5\WP\StyleReferences::attach( [ 'saved' => true ], $after, $before );
    $edits     = $adv['advisories']['components']['component_owned_edits'] ?? [];
    $check( 1 === count( $edits ) && 'divi/heading' === ( $edits[0]['block'] ?? '' ) && [ 'title' ] === ( $edits[0]['attributes'] ?? [] ), 'the save advisory flags a changed heading inside the instance (the component owns it)' );
    $check( [] === ( \AiEditorDivi5\WP\StyleReferences::attach( [ 'saved' => true ], $before, $before )['advisories']['components'] ?? [] ), 'and flags nothing when nothing changed' );
    $ghost = str_replace( '"globalModule":"' . $sectionId . '"', '"globalModule":"99999999"', $before );
    $check( 1 === count( \AiEditorDivi5\WP\StyleReferences::attach( [], $ghost )['advisories']['components']['unknown_components'] ?? [] ), 'an instance of a component the site does not have is flagged (Divi still shows the stored copy)' );
    $changedRender = $render( (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied verify components changed', 'post_content' => wp_slash( $after ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ] ) );
    $pageIds[]     = (int) get_posts( [ 'post_type' => 'page', 'post_status' => 'draft', 'title' => 'aied verify components changed', 'fields' => 'ids', 'numberposts' => 1 ] )[0];
    $check( false === strpos( strip_tags( $changedRender['html'] ), 'A better headline' ) && false !== strpos( strip_tags( $changedRender['html'] ), 'Component probe' ), 'proof for the advisory: the changed heading does NOT show, the component\'s own text does' );
} finally {
    $cleanup();
}

$check( 0 === $leftovers(), 'every throwaway page and library item was deleted (0 left)' );
if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Divi 5.15 components (instances, slots, nesting) validate and render on the real site.' );
