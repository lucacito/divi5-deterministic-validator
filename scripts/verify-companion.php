<?php
/**
 * Real-site check of the companion work (abilities and universal undo) on WordPress's real Abilities API and real post saves.
 *
 * Run: make verify-companion   (Docker dev site; the free plugin active; WordPress 6.9 or later)
 * It creates throwaway draft pages, a throwaway author and one throwaway ability, and removes them all again.
 * Stages (wp eval-file ... --user=1 -- <stage>): main (default), http-setup, http-check (used by scripts/verify-companion.sh).
 */

use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistorySources;

require_once '/scripts/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( HistoryService::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}
if ( ! function_exists( 'wp_register_ability' ) ) {
    WP_CLI::error( 'This WordPress has no Abilities API (6.9 or later is needed).' );
}

$stage    = $args[0] ?? 'main';
$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

const AIED_OLD = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
// A different valid layout per tag: one more shop module for every distinct tag (a trailing comment would be refused by the gate).
$variants   = [];
$newContent = static function ( string $tag ) use ( &$variants ): string {
    $variants[ $tag ] ??= count( $variants ) + 1;

    return str_replace( '<!-- /wp:divi/column -->', str_repeat( '<!-- wp:divi/shop {"builderVersion":"5.14.0"} /-->', $variants[ $tag ] ) . '<!-- /wp:divi/column -->', AIED_OLD );
};

$newPage = static function ( string $title ): int {
    $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => wp_slash( AIED_OLD ) ], true );
    if ( is_wp_error( $id ) ) {
        WP_CLI::error( $id->get_error_message() );
    }

    return (int) $id;
};
$last = static function ( int $id ): array {
    $v = HistoryService::listFor( $id );

    return $v[0] ?? [];
};

// ---- http stages: a real request signed in with an application password (the host runs curl between the two) ----
if ( 'http-setup' === $stage ) {
    $id = $newPage( 'aied companion http' );
    [ $password ] = WP_Application_Passwords::create_new_application_password( 1, [ 'name' => 'aied gate app' ] );
    WP_CLI::log( 'AIED_HTTP ' . wp_json_encode( [ 'page' => $id, 'password' => $password ] ) );
    exit( 0 );
}
if ( 'http-check' === $stage ) {
    $id = (int) ( $args[1] ?? 0 );
    $row = $last( $id );
    $check( 'application_password' === ( $row['tool'] ?? '' ) && 'aied gate app' === ( $row['label'] ?? '' ), 'a real HTTP save with an application password keeps an undo point labelled with the password\'s name' );
    $check( str_contains( (string) ( HistoryService::entry( $id, (int) ( $row['id'] ?? 0 ) )['content'] ?? '' ), 'wp:divi/shop' ), 'and it holds the content before the save' );
    foreach ( WP_Application_Passwords::get_user_application_passwords( 1 ) as $item ) {
        if ( 'aied gate app' === $item['name'] ) {
            WP_Application_Passwords::delete_application_password( 1, $item['uuid'] );
        }
    }
    wp_delete_post( $id, true );
    exit( [] === $failures ? 0 : 1 );
}

// ---- main ----------------------------------------------------------------------------------------------------------
$created = [];
$restore = [ 'options' => get_option( HistorySources::OPTION, null ) ];
add_action( 'wp_abilities_api_init', static function (): void {
    // A throwaway ability of "another plugin" that saves a page, to see how a foreign tool's save is credited.
    wp_register_ability( 'zz-aied-gate/write-page', [
        'label' => 'Gate write', 'description' => 'Throwaway ability used by the gate.', 'category' => 'jhmg-ai-editor',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer' ], 'content' => [ 'type' => 'string' ] ], 'required' => [ 'id', 'content' ] ],
        'execute_callback' => static function ( $in ) { wp_update_post( wp_slash( [ 'ID' => $in['id'], 'post_content' => $in['content'] ] ) ); return [ 'ok' => true ]; },
        'permission_callback' => static fn () => current_user_can( 'edit_pages' ),
        'meta' => [ 'public' => true, 'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ] ],
    ] );
}, 99 );

try {
    wp_set_current_user( 1 );

    // 1. Registration on the real API
    $mine = array_filter( wp_get_abilities(), static fn ( $a ) => str_starts_with( $a->get_name(), 'jhmg-ai-editor/' ) );
    $check( 17 === count( $mine ), '17 abilities are registered (' . count( $mine ) . ')' );
    $check( wp_has_ability_category( 'jhmg-ai-editor' ), 'the jhmg-ai-editor category exists' );
    $clash = array_filter( wp_get_abilities(), static fn ( $a ) => ! str_starts_with( $a->get_name(), 'jhmg-ai-editor/' ) && ( str_starts_with( $a->get_name(), 'divi/' ) || str_starts_with( $a->get_name(), 'et/' ) ) );
    WP_CLI::log( 'INFO  other abilities in a Divi namespace on this site: ' . count( $clash ) . ( [] === $clash ? '' : ' (' . implode( ', ', array_map( static fn ( $a ) => $a->get_name(), $clash ) ) . ')' ) );

    // 2. Over the REST API, as core exposes it
    $r = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) );
    $names = array_column( (array) $r->get_data(), 'name' );
    $check( 200 === $r->get_status() && in_array( 'jhmg-ai-editor/get-design-languages', $names, true ), 'REST lists the abilities' );
    $req = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/jhmg-ai-editor/get-design-languages/run' );
    $req->set_query_params( [ 'input' => [ 'id' => 'luxe' ] ] );
    $r = rest_do_request( $req );
    $check( 200 === $r->get_status() && 'luxe' === ( $r->get_data()['languages'][0]['id'] ?? '' ), 'a read-only ability runs over REST with GET' );
    $r = rest_do_request( new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/jhmg-ai-editor/get-design-languages/run' ) );
    $check( 405 === $r->get_status(), 'a read-only ability refuses POST (core\'s rule)' );

    // 3. Permissions
    $authorId = wp_insert_user( [ 'user_login' => 'aied_gate_author', 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
    $created[] = static fn () => wp_delete_user( $authorId );
    wp_set_current_user( $authorId );
    foreach ( [ 'get-style-guide', 'get-design-languages', 'check-design', 'update-page-layout', 'list-divi-pages' ] as $name ) {
        $a = wp_get_ability( 'jhmg-ai-editor/' . $name );
        $check( false === $a->check_permissions( [] ) || is_wp_error( $a->check_permissions( [] ) ), "an author is denied {$name}" );
    }
    $r = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/jhmg-ai-editor/get-style-guide/run' ) );
    $check( in_array( $r->get_status(), [ 401, 403 ], true ), 'and over REST the author gets ' . $r->get_status() );
    wp_set_current_user( 0 );
    $a = wp_get_ability( 'jhmg-ai-editor/get-style-guide' );
    $check( true !== $a->check_permissions( [] ), 'a visitor who is not signed in is denied' );
    wp_set_current_user( 1 );

    $pageA = $newPage( 'aied companion A' );
    $created[] = static fn () => wp_delete_post( $pageA, true );
    $editorId = wp_insert_user( [ 'user_login' => 'aied_gate_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor' ] );
    $created[] = static fn () => wp_delete_user( $editorId );
    $other = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied companion private', 'post_content' => wp_slash( AIED_OLD ), 'post_author' => 1 ], true );
    $created[] = static fn () => wp_delete_post( $other, true );
    $privateOnly = static function ( $caps, $cap, $uid, $args ) use ( $other, $editorId ) { // deny edit_post on one page for the editor
        if ( 'edit_post' === $cap && $uid === $editorId && isset( $args[0] ) && (int) $args[0] === $other ) { return [ 'do_not_allow' ]; }
        return $caps;
    };
    add_filter( 'map_meta_cap', $privateOnly, 10, 4 );
    wp_set_current_user( $editorId );
    $a = wp_get_ability( 'jhmg-ai-editor/get-page-layout' );
    $check( true === $a->check_permissions( [ 'page_id' => $pageA ] ), 'an editor may read a page it can edit' );
    $check( true !== $a->check_permissions( [ 'page_id' => $other ] ), 'but not a page it lacks edit_post for' );
    $r = $a->execute( [ 'page_id' => $other ] );
    $check( is_wp_error( $r ) && 'ability_invalid_permissions' === $r->get_error_code(), 'execute() refuses it too' );
    remove_filter( 'map_meta_cap', $privateOnly, 10 );
    wp_set_current_user( 1 );

    // 4. Our own tools through abilities: gate, undo point, restore
    $r = wp_get_ability( 'jhmg-ai-editor/update-page-layout' )->execute( [ 'page_id' => $pageA, 'post_content' => 'not a divi layout' ] );
    $check( is_wp_error( $r ) && 'jhmg_rejected' === $r->get_error_code(), 'an invalid layout is refused by the validator gate' );
    $check( AIED_OLD === get_post( $pageA )->post_content, 'and the page is untouched' );
    $r = wp_get_ability( 'jhmg-ai-editor/update-page-layout' )->execute( [ 'page_id' => $pageA, 'post_content' => $newContent( 'A1' ), 'dry_run' => true ] );
    $check( ! is_wp_error( $r ) && AIED_OLD === get_post( $pageA )->post_content, 'dry_run previews without saving' );
    $r = wp_get_ability( 'jhmg-ai-editor/update-page-layout' )->execute( [ 'page_id' => $pageA, 'post_content' => $newContent( 'A1' ) ] );
    $check( ! is_wp_error( $r ) && true === ( $r['history']['stored'] ?? false ), 'a valid layout is saved with an undo point' . ( is_wp_error( $r ) ? ' [' . substr( $r->get_error_message(), 0, 300 ) . ']' : ' [' . substr( wp_json_encode( $r['history'] ?? $r ), 0, 200 ) . ']' ) );
    $check( 1 === count( HistoryService::listFor( $pageA ) ) && 'update_page_layout' === ( $last( $pageA )['tool'] ?? '' ), 'exactly ONE snapshot, with the plugin\'s own tool name (no double capture)' );

    // 5. Universal capture: other sources
    $stamp = 0;
    $save = static function ( int $id ) use ( &$stamp, $newContent ): void { ++$stamp; wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => $newContent( 'save' . $stamp ) ] ) ); };
    \AiEditorDivi5\WP\HistoryCapture::reset(); // earlier REST calls in this one process left their route behind; a real request has one route
    $before = count( HistoryService::listFor( $pageA ) );
    $save( $pageA );
    $row = $last( $pageA );
    $check( count( HistoryService::listFor( $pageA ) ) === $before + 1 && 'unknown' === $row['tool'], 'a save with no known source is kept and labelled unknown' );

    $req = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $pageA );
    $req->set_body_params( [ 'content' => $newContent( 'rest' ) ] );
    $r = rest_do_request( $req );
    $check( 200 === $r->get_status() && 'rest' === ( $last( $pageA )['tool'] ?? '' ), 'a core REST save is kept and labelled rest (status ' . $r->get_status() . ', tool ' . ( $last( $pageA )['tool'] ?? '?' ) . ')' );

    $r = wp_get_ability( 'zz-aied-gate/write-page' )->execute( [ 'id' => $pageA, 'content' => $newContent( 'ability' ) ] );
    $row = $last( $pageA );
    $check( 'ability' === $row['tool'] && 'zz-aied-gate/write-page' === $row['label'], 'another plugin\'s ability save is labelled with that ability\'s name' );
    \AiEditorDivi5\WP\HistoryCapture::reset();
    $save( $pageA );
    $check( 'unknown' === $last( $pageA )['tool'], 'and the name is forgotten once the ability has finished' );

    // builder saves are off by default; switched on they are kept
    $req = new WP_REST_Request( 'POST', '/divi/v1/sync-to-server' );
    update_option( HistorySources::OPTION, HistorySources::DEFAULTS );
    $count = count( HistoryService::listFor( $pageA ) );
    \AiEditorDivi5\WP\HistoryCapture::onRestPreDispatch( null, null, $req );
    $save( $pageA );
    \AiEditorDivi5\WP\HistoryCapture::reset();
    $check( count( HistoryService::listFor( $pageA ) ) === $count, 'a save through Divi\'s builder route is not kept by default' );
    update_option( HistorySources::OPTION, array_merge( HistorySources::DEFAULTS, [ 'builder' => true ] ) );
    \AiEditorDivi5\WP\HistoryCapture::onRestPreDispatch( null, null, $req );
    $save( $pageA );
    \AiEditorDivi5\WP\HistoryCapture::reset();
    $check( 'builder' === ( $last( $pageA )['tool'] ?? '' ), 'and is kept, labelled builder, once switched on' );
    update_option( HistorySources::OPTION, HistorySources::DEFAULTS );

    // 6. Restore of a foreign snapshot, and the restore is undoable
    $pageB = $newPage( 'aied companion B' );
    $created[] = static fn () => wp_delete_post( $pageB, true );
    \AiEditorDivi5\WP\HistoryCapture::reset();
    $save( $pageB );
    $afterForeign = get_post( $pageB )->post_content;
    $foreign = $last( $pageB );
    $r = wp_get_ability( 'jhmg-ai-editor/restore-page-version' )->execute( [ 'page_id' => $pageB, 'version_id' => (int) $foreign['id'] ] );
    $check( ! is_wp_error( $r ) && AIED_OLD === get_post( $pageB )->post_content, 'a snapshot from another source restores the old content' );
    $undo = $last( $pageB );
    $check( 'restore' === $undo['tool'], 'and the restore took its own undo point' );
    $r = wp_get_ability( 'jhmg-ai-editor/restore-page-version' )->execute( [ 'page_id' => $pageB, 'version_id' => (int) $undo['id'] ] );
    $check( ! is_wp_error( $r ) && $afterForeign === get_post( $pageB )->post_content, 'undoing the restore brings the other tool\'s change back' );

    // 7. The advisory abilities on real content
    $r = wp_get_ability( 'jhmg-ai-editor/check-design' )->execute( [ 'page_id' => $pageA ] );
    $check( ! is_wp_error( $r ) && isset( $r['checks']['contrast'], $r['checks']['default_fallbacks'] ), 'check-design answers with its checks on a stored page' );
    $r = wp_get_ability( 'jhmg-ai-editor/get-variety-advice' )->execute( [ 'page_id' => $pageA ] );
    $check( ! is_wp_error( $r ) && array_key_exists( 'applicable', $r ), 'get-variety-advice answers' );
    $r = wp_get_ability( 'jhmg-ai-editor/get-section-recipes' )->execute( [ 'role' => 'pricing', 'density' => 'balanced' ] );
    $check( ! is_wp_error( $r ) && $r['count'] >= 1, 'get-section-recipes filters by role and density' );
    $r = wp_get_ability( 'jhmg-ai-editor/list-divi-pages' )->execute();
    $check( ! is_wp_error( $r ) && isset( $r['pages'] ), 'list-divi-pages answers' );
} finally {
    foreach ( array_reverse( $created ) as $undo ) {
        $undo();
    }
    wp_unregister_ability( 'zz-aied-gate/write-page' );
    if ( null === $restore['options'] ) {
        delete_option( HistorySources::OPTION );
    } else {
        update_option( HistorySources::OPTION, $restore['options'] );
    }
}

WP_CLI::log( '' );
if ( [] === $failures ) {
    WP_CLI::success( 'companion gate: all checks passed' );
} else {
    WP_CLI::error( count( $failures ) . ' check(s) failed' );
}
