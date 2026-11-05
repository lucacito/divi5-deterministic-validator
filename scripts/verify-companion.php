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

// ---- pro stage: the Pro abilities, batches for any source, design review and strict validation -----------------------
if ( 'pro' === $stage ) {
    if ( ! class_exists( \AiEditorDivi5\Pro\Companion\ProAbilities::class ) ) {
        WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
    }
    $created = [];
    $saved   = [
        'sessions' => get_option( \AiEditorDivi5\Pro\Companion\AgentSessions::OPTION, null ),
        'strict'   => get_option( \AiEditorDivi5\Pro\Companion\StrictMode::OPTION, null ),
        'flags'    => get_option( \AiEditorDivi5\Pro\Companion\StrictMode::FLAGS, null ),
        'premium'  => get_option( 'aied_pro_premium_unlocked', null ),
    ];
    $divi5 = static function ( int $id ): int { update_post_meta( $id, '_et_pb_use_divi_5', 'on' ); return $id; };
    $restSave = static function ( int $id, string $content ): int {
        \AiEditorDivi5\WP\HistoryCapture::reset();
        $req = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $id );
        $req->set_body_params( [ 'content' => $content ] );

        return rest_do_request( $req )->get_status();
    };
    $call = static fn ( string $name, array $in = [] ) => wp_get_ability( 'jhmg-ai-editor-pro/' . $name )->execute( $in );
    try {
        wp_set_current_user( 1 );
        update_option( 'aied_pro_premium_unlocked', 1, false );

        $mine = array_filter( wp_get_abilities(), static fn ( $a ) => str_starts_with( $a->get_name(), 'jhmg-ai-editor-pro/' ) );
        $check( 7 === count( $mine ), '7 Pro abilities are registered (' . count( $mine ) . ')' );
        $check( wp_has_ability_category( 'jhmg-ai-editor-pro' ), 'the jhmg-ai-editor-pro category exists' );

        // licence and capability
        delete_option( 'aied_pro_premium_unlocked' );
        $r = wp_get_ability( 'jhmg-ai-editor-pro/audit-site' )->execute( [] );
        $check( is_wp_error( $r ) && 'ability_invalid_permissions' === $r->get_error_code(), 'without a licence a Pro ability is refused' );
        update_option( 'aied_pro_premium_unlocked', 1, false );
        $authorId = wp_insert_user( [ 'user_login' => 'aied_gate_pro_author', 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
        $created[] = static fn () => wp_delete_user( $authorId );
        wp_set_current_user( $authorId );
        $check( true !== wp_get_ability( 'jhmg-ai-editor-pro/design-review-site' )->check_permissions( [] ), 'an author is refused even with a licence' );
        wp_set_current_user( 1 );

        // a page with problems: unreadable text, a link to nowhere, Divi's grey placeholder
        $export = json_decode( (string) file_get_contents( '/fixtures/valid/page-17-newpage.json' ), true );
        preg_match( '#data:image/svg\+xml;base64,[A-Za-z0-9+/=]+#', (string) $export['post_content'], $ph );
        $bad = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Hello</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#fafafa"}}}}}}},"builderVersion":"5.14.0"} /-->'
            . '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"' . $ph[0] . '","alt":"x"}}}},"builderVersion":"5.14.0"} /-->'
            . '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"/definitely-missing-page-aied/"}}}},"builderVersion":"5.14.0"} /-->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $pBad = $divi5( $newPage( 'aied companion pro bad' ) );
        $created[] = static fn () => wp_delete_post( $pBad, true );
        wp_update_post( wp_slash( [ 'ID' => $pBad, 'post_content' => $bad ] ) );

        $r = $call( 'audit-site', [ 'scope' => 'pages', 'cursor' => $pBad - 1 ] );
        $check( ! is_wp_error( $r ) && isset( $r['findings'], $r['design'] ), 'audit-site returns the audit and a design section' );
        $mine = array_values( array_filter( (array) ( $r['design']['pages_with_problems'] ?? [] ), static fn ( $p ) => $p['id'] === $pBad ) );
        $check( [] !== $mine && isset( $mine[0]['design_problems']['LOW_CONTRAST'], $mine[0]['design_problems']['GREY_PLACEHOLDER_IMAGE'] ), 'it reports the planted page\'s contrast and placeholder problems by page' );

        $r = $call( 'design-review-site', [ 'scope' => 'pages', 'cursor' => $pBad - 1 ] );
        $codes = ! is_wp_error( $r ) ? array_column( array_filter( $r['fixes'], static fn ( $f ) => $f['id'] === $pBad ), 'priority', 'code' ) : [];
        $check( 'high' === ( $codes['LOW_CONTRAST'] ?? '' ), 'design-review-site lists the unreadable text as a high priority fix' );
        $check( 'high' === ( $codes['BROKEN_INTERNAL_LINK'] ?? '' ), 'and the link to a missing page as high' );
        $check( 'medium' === ( $codes['GREY_PLACEHOLDER_IMAGE'] ?? '' ), 'and Divi\'s grey placeholder as medium' );
        $prios = ! is_wp_error( $r ) ? array_column( $r['fixes'], 'priority' ) : [];
        $check( $prios === array_values( array_merge( array_filter( $prios, fn ( $x ) => 'high' === $x ), array_filter( $prios, fn ( $x ) => 'medium' === $x ), array_filter( $prios, fn ( $x ) => 'low' === $x ) ) ), 'the list is ordered high, then medium, then low' );

        // batch undo for any source, explicit session
        $pA = $divi5( $newPage( 'aied companion batch A' ) );
        $pB = $divi5( $newPage( 'aied companion batch B' ) );
        $created[] = static fn () => wp_delete_post( $pA, true );
        $created[] = static fn () => wp_delete_post( $pB, true );
        sleep( 2 ); // history times have one-second resolution: a change in the same second as start-batch counts as inside it
        $start = $call( 'start-batch', [ 'label' => 'gate redesign' ] );
        $check( ! is_wp_error( $start ) && str_starts_with( (string) $start['batch_id'], 'session-' ), 'start-batch returns a batch id' );
        $check( 200 === $restSave( $pA, $newContent( 'pa' ) ) && 200 === $restSave( $pB, $newContent( 'pb' ) ), 'two pages are changed through core REST, a tool this plugin did not write' );
        $end = $call( 'end-batch' );
        $check( ! is_wp_error( $end ) && true === $end['closed'], 'end-batch closes it' );
        $list = $call( 'list-batches' );
        $found = ! is_wp_error( $list ) ? array_values( array_filter( $list['agent_batches'], static fn ( $b ) => $b['id'] === $start['batch_id'] ) ) : [];
        $check( 1 === count( $found ) && 2 === $found[0]['page_count'], 'list-batches shows the session with both pages ' . wp_json_encode( [ count( $found ), $found[0]['page_count'] ?? null, wp_json_encode( $found[0]['pages'] ?? null ), $pA, $pB, $pBad ] ) );
        $dry = $call( 'undo-batch', [ 'batch_id' => $start['batch_id'], 'dry_run' => true ] );
        $check( ! is_wp_error( $dry ) && 2 === count( $dry['would_restore'] ) && $newContent( 'pa' ) === get_post( $pA )->post_content, 'a dry run plans both restores and changes nothing' );
        $undo = $call( 'undo-batch', [ 'batch_id' => $start['batch_id'] ] );
        $check( ! is_wp_error( $undo ) && true === $undo['fully_undone'] && AIED_OLD === get_post( $pA )->post_content && AIED_OLD === get_post( $pB )->post_content, 'undo-batch puts both pages back' );
        $again = $call( 'undo-batch', [ 'batch_id' => $start['batch_id'] ] );
        $check( ! is_wp_error( $again ) && [] === $again['restored'] && 2 === count( $again['skipped'] ), 'a second undo changes nothing (the pages changed since)' );
        $check( null !== get_post( $pA ) && 'trash' !== get_post( $pA )->post_status, 'no page was trashed or deleted' );

        // a page changed after the batch is skipped, never overwritten
        sleep( 2 ); // two batches inside one second cannot be told apart (history times have one-second resolution)
        $start2 = $call( 'start-batch', [ 'label' => 'second' ] );
        $restSave( $pA, $newContent( 'pa2' ) );
        $restSave( $pB, $newContent( 'pb2' ) );
        $call( 'end-batch' );
        sleep( 2 );
        \AiEditorDivi5\WP\HistoryCapture::reset();
        wp_update_post( wp_slash( [ 'ID' => $pA, 'post_content' => $newContent( 'later' ) ] ) );
        $undo2 = $call( 'undo-batch', [ 'batch_id' => $start2['batch_id'] ] );
        $check( ! is_wp_error( $undo2 ) && [ $pB ] === array_column( $undo2['restored'], 'page_id' ) && [ $pA ] === array_column( $undo2['skipped'], 'page_id' ), 'the page changed after the batch is skipped, the other restored' );
        $check( $newContent( 'later' ) === get_post( $pA )->post_content, 'and the newer change was not overwritten' );

        // an inferred batch (no start-batch): same source close together
        $pC = $divi5( $newPage( 'aied companion batch C' ) );
        $created[] = static fn () => wp_delete_post( $pC, true );
        delete_option( \AiEditorDivi5\Pro\Companion\AgentSessions::OPTION );
        $restSave( $pC, $newContent( 'c1' ) );
        $restSave( $pC, $newContent( 'c2' ) );
        $list = $call( 'list-batches' );
        $inf = ! is_wp_error( $list ) ? array_values( array_filter( $list['agent_batches'], static fn ( $b ) => 'inferred' === $b['kind'] && in_array( $pC, array_column( $b['pages'], 'page_id' ), true ) ) ) : [];
        $check( [] !== $inf && $inf[0]['changes'] >= 2, 'without start-batch the changes are still grouped by source and time' );

        // strict validation: flags after the save, blocks nothing
        update_option( \AiEditorDivi5\Pro\Companion\StrictMode::OPTION, '1', false );
        delete_option( \AiEditorDivi5\Pro\Companion\StrictMode::FLAGS );
        $status = $restSave( $pC, $bad . '<!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /-->' );
        $check( 200 === $status, 'strict mode does not block an invalid save by REST (status ' . $status . ')' );
        $flags = \AiEditorDivi5\Pro\Companion\StrictMode::flags();
        $check( [] !== $flags && $pC === $flags[0]['post_id'] && 'rest' === $flags[0]['source'], 'but the page is flagged with its source' );
        $check( str_contains( get_post( $pC )->post_content, 'not-a-module' ), 'and nothing was rolled back' );
        $restSave( $pC, $newContent( 'valid-again' ) );
        $check( [] === \AiEditorDivi5\Pro\Companion\StrictMode::flags(), 'a later valid save clears the flag' );
        update_option( \AiEditorDivi5\Pro\Companion\StrictMode::OPTION, '0', false );
        $restSave( $pC, $bad . '<!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /-->' );
        $check( [] === \AiEditorDivi5\Pro\Companion\StrictMode::flags(), 'with it off nothing is flagged' );
    } finally {
        foreach ( array_reverse( $created ) as $undo ) {
            $undo();
        }
        foreach ( [ 'sessions' => \AiEditorDivi5\Pro\Companion\AgentSessions::OPTION, 'strict' => \AiEditorDivi5\Pro\Companion\StrictMode::OPTION, 'flags' => \AiEditorDivi5\Pro\Companion\StrictMode::FLAGS, 'premium' => 'aied_pro_premium_unlocked' ] as $k => $opt ) {
            null === $saved[ $k ] ? delete_option( $opt ) : update_option( $opt, $saved[ $k ], false );
        }
    }
    WP_CLI::log( '' );
    if ( [] === $failures ) {
        WP_CLI::success( 'companion Pro gate: all checks passed' );
        exit( 0 );
    }
    WP_CLI::error( count( $failures ) . ' check(s) failed' );
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
