<?php
/**
 * Real-site check of the Pro tool lab_restyle (Pro Lab plan Task 9, the PHP half; the orchestrator and the render comparison are
 * scripts/verify-pro-lab-restyle.cjs, `make verify-pro-lab-restyle`). One stage per wp eval-file call:
 *
 *   build <runToken>      makes three throwaway users (`aied_lr_<runToken>_a` an administrator who owns every Lab brief, `_e` an
 *                         editor, `_w` an author) and, through the REAL free `lab_build` REST route with create:true, the Lab pages
 *                         `aied labrestyle <runToken> rest|mcp|changed|briefedit|published|kses|denied` (owned by _a, editorial and its
 *                         own kit) and `... editor` (owned by _e); each is linked to its brief with the sha256 of the content as stored
 *   restyle <runToken>    REST dry run (nothing changes, valid, a token) and run with the token on `rest`: the stored page EQUALS a
 *                         reference page `ref-rest` whose content was written from the direct compile of the restyled brief through the
 *                         same core save path (HistoryService::write, same user), byte for byte; the brief row holds the new look, the
 *                         new brief hash and the sha256 of the stored content; the token is single use, a missing, made-up or
 *                         other-argument token is refused; the MCP handler (as the brief owner) gives the same preview and run payloads
 *                         and the same saved bytes on `mcp`; Lab's screen opens the restyled brief and the free StudioService::rebuild
 *                         overwrites the untouched restyled page in place; permissions: an author 403, an editor sees the admin's
 *                         page as not_lab_page, a bare {id, reason: forbidden} without edit_post, and only their own page with all_mine
 *   changed <runToken>    a page edited between the preview and the run, and a page whose brief was edited between them, are skipped
 *                         and never overwritten
 *   published <runToken>  a published Lab page is skipped without include_published and restyled with it (still published)
 *   kses <runToken>       one restyle as the brief owner with unfiltered_html removed by a temporary map_meta_cap filter stores no
 *                         visible block code and equals its reference saved the same way
 *   undo <runToken>       undo_batch (as user 1, not the owner) restores the page bytes and the brief row exactly, Lab's rebuild then
 *                         overwrites in place, undo of the undo brings the restyle back exactly, and the rebuild still overwrites
 *   cleanup <runToken|all>  deletes the pages (`all`: every `aied labrestyle ` page), the brief rows of the throwaway users, the users
 *                         and the batch rows this gate recorded
 *
 * Output: JSON lines on stdout, { "type": "check", "ok": bool, "label": ..., "detail": ... } per check and { "type": "info", ... }; the exit
 * status is 1 when a check failed. A FAIL is a finding about the tool: report it, never edit this script to hide it.
 *
 * Needs `make up`, the free plugin and the Pro add-on active on the dev site (--user=1, an administrator). No licence is needed: the registry
 * is built with the unlock forced, as the other verify-pro-* gates do. Dev site only. It records up to six batches (the store keeps the
 * newest 20: older ones may be evicted on a site that already has many); it deletes the batch rows it made.
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\LabRestyle;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioScreen;
use AiEditorDivi5\WP\StudioService;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( LabRestyle::class ) || ! class_exists( StudioService::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with Divi 5 Lab) and jhmg-ai-editor-for-divi-5-pro (with lab_restyle) on this site first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
require_once ABSPATH . 'wp-admin/includes/user.php';

const AIED_LR_TITLE = 'aied labrestyle ';
const AIED_LR_USER  = 'aied_lr_';
const AIED_LR_LAB   = [ 'rest', 'mcp', 'changed', 'briefedit', 'published', 'kses', 'denied' ];
const AIED_LR_ROUTE = '/pro/lab_restyle';

$stage = (string) ( $args[0] ?? '' );
$run   = (string) ( $args[1] ?? '' );
if ( ! in_array( $stage, [ 'build', 'restyle', 'changed', 'published', 'kses', 'undo', 'cleanup' ], true ) ) {
    WP_CLI::error( 'usage: wp eval-file verify-pro-lab-restyle.php <build|restyle|changed|published|kses|undo|cleanup> <runToken|all>' );
}
if ( 1 !== preg_match( '/^\d+-[0-9a-f]{6}$/', $run ) && ! ( 'cleanup' === $stage && 'all' === $run ) ) {
    WP_CLI::error( 'the run token must look like 1234-abcdef' );
}

global $wpdb;

$failures = [];
$emit     = static function ( array $o ): void {
    echo wp_json_encode( $o ) . "\n";
};
$check = static function ( bool $ok, string $label, mixed $detail = null ) use ( &$failures, $emit ): void {
    if ( ! $ok ) {
        $failures[] = $label;
    }
    $emit( [ 'type' => 'check', 'ok' => $ok, 'label' => $label, 'detail' => is_string( $detail ) ? substr( $detail, 0, 600 ) : ( null === $detail ? null : substr( (string) wp_json_encode( $detail ), 0, 600 ) ) ] );
};
$info = static function ( string $key, mixed $value ) use ( $emit ): void {
    $emit( [ 'type' => 'info', 'key' => $key, 'value' => $value ] );
};

// ---- helpers ----------------------------------------------------------------------------------------------------------------
$title = static fn ( string $role ): string => AIED_LR_TITLE . $run . ' ' . $role;
$find  = static function ( string $role ) use ( $title, $wpdb ): int {
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title = %s ORDER BY ID DESC LIMIT 1", $title( $role ) ) ); // phpcs:ignore
};
$user = static function ( string $k ) use ( $run ): int {
    $u = get_user_by( 'login', AIED_LR_USER . $run . '_' . $k );

    return $u instanceof WP_User ? (int) $u->ID : 0;
};
$stored = static function ( int $id ): string {
    clean_post_cache( $id );
    $p = get_post( $id );

    return $p ? (string) $p->post_content : '';
};
$status = static function ( int $id ): string {
    clean_post_cache( $id );
    $p = get_post( $id );

    return $p ? (string) $p->post_status : '';
};
$sha   = static fn ( string $s ): string => substr( sha1( $s ), 0, 12 ) . '/' . strlen( $s );
$norm  = static fn ( mixed $v ): mixed => json_decode( (string) wp_json_encode( $v ), true );
/** The brief row fields that matter (updated_at and plugin_version are bookkeeping). */
$rowOf = static function ( int $owner, int $briefId ): ?array {
    $r = StudioStore::get( $owner, $briefId );

    return null === $r ? null : [ 'id' => $r['id'], 'brief' => $r['brief'], 'brief_hash' => $r['brief_hash'], 'page_id' => $r['page_id'], 'content_hash' => $r['content_hash'] ];
};
/** The brief of a Lab page as its owner's store holds it, or null. */
$labRow = static function ( int $owner, int $pageId ) use ( $rowOf ): ?array {
    $r = StudioStore::forPage( $owner, $pageId );

    return null === $r ? null : $rowOf( $owner, (int) $r['id'] );
};
$userRows = static function ( int $owner ) use ( $wpdb ): int {
    if ( ! StudioTable::exists() ) {
        return 0;
    }

    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . StudioTable::name() . ' WHERE created_by = %d', $owner ) ); // phpcs:ignore
};

/** The brief every Lab page of this gate is built from: five recipes, a few words with quotes and an ampersand, editorial and its own kit. */
$brief = static function ( string $role ) use ( $title ): array {
    return [
        'version'  => 1,
        'title'    => $title( $role ),
        'goal'     => 'book',
        'language' => 'editorial',
        'sections' => [
            [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => 'Calm rooms & "honest" prices', 'button.0.text' => 'Book a stay', 'button.0.link' => '#book' ] ],
            [ 'recipe' => 'blurb-grid', 'slots' => [ 'heading.0' => 'Why guests return' ] ],
            [ 'recipe' => 'stats-counter', 'slots' => [] ],
            [ 'recipe' => 'faq-accordion', 'slots' => [ 'heading.1' => 'Questions, answered' ] ],
            [ 'recipe' => 'cta-band', 'slots' => [ 'heading.0' => 'Ready when you are', 'button.0.link' => '#book' ] ],
        ],
    ];
};
$target = [ 'luxe', 'gilt-hairline' ];
/** The brief with the target look, worked out here (not by the tool): language and kit set, nothing else touched. */
$restyled = static function ( array $b ) use ( $target ): array {
    $b['language'] = $target[0];
    $b['kit']      = $target[1];

    return $b;
};
/** The direct compile of a brief, as the free Lab service does it for this site (StudioService::compile is private; same arguments). */
$compile = static function ( array $b ): array {
    return StudioCompiler::compile( $b, static fn ( string $path ): bool => url_to_postid( home_url( $path ) ) > 0, (string) home_url() );
};
/**
 * The reference page `ref-<role>`: a draft whose content is the direct compile of $b, written through HistoryService::write as the
 * CURRENT user (the same core save path lab_restyle uses), so stored bytes compare with stored bytes. Returns its stored content.
 */
$writeRef = static function ( string $role, array $b ) use ( $title, $find, $compile, $stored, $check ): string {
    $c = $compile( $b );
    if ( empty( $c['ok'] ) ) {
        $check( false, "the reference {$role} compiles", $c['problems'] ?? $c['violations'] ?? null );

        return '';
    }
    $id = $find( $role );
    if ( $id <= 0 ) {
        $id = wp_insert_post( wp_slash( [
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $title( $role ),
            'post_content' => '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->',
            'meta_input'   => [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ],
        ] ), true );
        if ( is_wp_error( $id ) ) {
            $check( false, "the reference page {$role} was inserted", $id->get_error_message() );

            return '';
        }
    }
    $w = HistoryService::write( (int) $id, (string) $c['markup'], 'aied_gate_reference' );
    if ( empty( $w['ok'] ) ) {
        $check( false, "the reference page {$role} was written", $w );

        return '';
    }

    return $stored( (int) $id );
};

$ns   = '/ai-editor-divi5/v1';
$rest = static function ( string $route, array $body ) use ( $ns ): array {
    $req = new WP_REST_Request( 'POST', $ns . $route );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    $res  = rest_do_request( $req );
    $data = $res->get_data();
    if ( $data instanceof WP_Error ) {
        $data = [ 'code' => $data->get_error_code(), 'message' => $data->get_error_message() ];
    }

    return [ 'status' => $res->get_status(), 'data' => (array) $data ];
};
/**
 * The free MCP endpoint as an assistant calls it: Bearer API key, JSON-RPC, signed in as the key's user. The key's user is set to $as for
 * this one call and put back afterwards; the current user is then $as again.
 */
$mcpRpc = static function ( string $method, array $params, int $as ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ] ) );
    $before = get_option( 'ai_editor_divi5_api_user_id', null );
    update_option( 'ai_editor_divi5_api_user_id', $as, false );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    try {
        $res = rest_do_request( $req );
    } finally {
        unset( $_SERVER['HTTP_AUTHORIZATION'] );
        if ( null === $before ) {
            delete_option( 'ai_editor_divi5_api_user_id' );
        } else {
            update_option( 'ai_editor_divi5_api_user_id', $before, false );
        }
        wp_set_current_user( $as );
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => (array) $data['error'], 'result' => null ];
    }

    return [ 'error' => null, 'result' => (array) ( $data['result'] ?? [] ) ];
};
$mcpTool = static function ( string $tool, array $arguments, int $as ) use ( $mcpRpc ): array {
    $r = $mcpRpc( 'tools/call', [ 'name' => $tool, 'arguments' => $arguments ], $as );
    if ( null !== $r['error'] ) {
        return [ 'error' => $r['error'], 'isError' => true, 'result' => null ];
    }
    $text = (string) ( $r['result']['content'][0]['text'] ?? '' );

    return [ 'error' => null, 'isError' => (bool) ( $r['result']['isError'] ?? false ), 'result' => json_decode( $text, true ) ];
};
/** A payload with what differs between two pages of the same brief taken out: ids, titles, the token and the batch. */
$strip = static function ( mixed $payload ) use ( $norm ): mixed {
    $p = $norm( $payload );
    if ( ! is_array( $p ) ) {
        return $p;
    }
    unset( $p['token'], $p['batch'] );
    foreach ( [ 'pages', 'skipped' ] as $k ) {
        foreach ( (array) ( $p[ $k ] ?? [] ) as $i => $row ) {
            unset( $p[ $k ][ $i ]['id'], $p[ $k ][ $i ]['title'], $p[ $k ][ $i ]['brief_id'] );
        }
    }

    return $p;
};
$addBatch = static function ( int $pageId, mixed $batch ): int {
    $b = is_array( $batch ) ? (int) ( $batch['id'] ?? 0 ) : 0;
    if ( $pageId > 0 && $b > 0 ) {
        add_post_meta( $pageId, '_aied_lr_batch', $b );
    }

    return $b;
};
/** The full check of one restyled page: stored bytes equal the reference, the brief row has the new look and the stored content's hash. */
$restyledRight = static function ( string $role, int $owner, int $pageId, array $before, string $ref ) use ( $check, $stored, $rowOf, $restyled, $sha ): void {
    $now = $stored( $pageId );
    $check( '' !== $ref && $now === $ref, "{$role}: the stored restyled page EQUALS the reference saved from the direct compile through the same save path, byte for byte", $sha( $now ) . ' vs ' . $sha( $ref ) );
    $check( '' !== $now && LayoutGate::check( $now )->isValid(), "{$role}: the stored restyled page passes the layout gate" );
    $exp = $restyled( $before['brief'] );
    $row = $rowOf( $owner, (int) $before['id'] );
    $check( null !== $row && $row['brief'] === $exp, "{$role}: the brief row holds the brief with the new look and nothing else changed", null === $row ? null : [ 'language' => $row['brief']['language'] ?? null, 'kit' => $row['brief']['kit'] ?? null ] );
    $check( null !== $row && $row['brief_hash'] === StudioStore::hashOf( $exp ) && $row['brief_hash'] !== $before['brief_hash'], "{$role}: the brief row has the new brief hash" );
    $check( null !== $row && $row['content_hash'] === hash( 'sha256', $now ) && $row['page_id'] === $pageId, "{$role}: the brief row is linked to the page with content_hash = sha256(stored content)", null === $row ? null : [ 'page_id' => $row['page_id'], 'content_hash' => substr( (string) $row['content_hash'], 0, 12 ), 'sha256' => substr( hash( 'sha256', $now ), 0, 12 ) ] );
};

// The tools are served as the add-on serves them when licensed: unlock forced, routes registered, MCP hooks added.
if ( in_array( $stage, [ 'restyle', 'changed', 'published', 'kses', 'undo' ], true ) ) {
    $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
    $registry->register();
    ( new ProRest( $registry ) )->register();
    $GLOBALS['wp_rest_server'] = null; // WP-CLI built the server before these hooks existed
}

wp_set_current_user( 1 );
$A = $user( 'a' );
$E = $user( 'e' );
$W = $user( 'w' );

try {
    // =========================================================================================================================
    if ( 'build' === $stage ) {
        $tool = ( new ToolRegistry( Tools::all(), static fn (): bool => true ) )->get( LabRestyle::NAME );
        $check( $tool instanceof LabRestyle, 'the lab_restyle tool is registered' );
        $check( $tool instanceof LabRestyle && null === $tool->unsupported(), 'the installed free plugin supports lab_restyle (no "update the free plugin" refusal)', $tool instanceof LabRestyle ? $tool->unsupported() : null );

        foreach ( [ 'a' => 'administrator', 'e' => 'editor', 'w' => 'author' ] as $k => $role ) {
            $login = AIED_LR_USER . $run . '_' . $k;
            $id    = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.invalid', 'role' => $role, 'display_name' => 'aied labrestyle ' . $run . ' ' . $k ] );
            $check( ! is_wp_error( $id ), "a throwaway {$role} ({$k}) was created", is_wp_error( $id ) ? $id->get_error_message() : null );
        }
        $A = $user( 'a' );
        $E = $user( 'e' );
        $check( $A > 0 && $E > 0 && user_can( $A, 'unfiltered_html' ) && user_can( $E, 'edit_others_pages' ) && ! user_can( $user( 'w' ), 'edit_pages' ), 'the throwaway users have the expected rights (admin: unfiltered_html; editor: edit_others_pages; author: no edit_pages)' );
        $check( 0 === $userRows( $A ) && 0 === $userRows( $E ), 'the throwaway users hold no Lab brief yet' );

        $make = static function ( string $role, int $owner ) use ( $brief, $rest, $find, $stored, $labRow, $compile, $check ): void {
            wp_set_current_user( $owner );
            $r = $rest( '/lab-build', [ 'action' => 'build', 'create' => true, 'brief' => $brief( $role ) ] );
            wp_set_current_user( 1 );
            $id = $find( $role );
            $check( 200 === $r['status'] && true === ( $r['data']['created'] ?? null ) && $id > 0 && $id === (int) ( $r['data']['page']['id'] ?? 0 ), "lab_build create:true made the Lab page {$role} (id {$id})", $r['status'] . ' ' . wp_json_encode( array_intersect_key( $r['data'], [ 'code' => 1, 'message' => 1, 'created' => 1 ] ) ) );
            if ( $id <= 0 ) {
                return;
            }
            $s   = $stored( $id );
            $row = $labRow( $owner, $id );
            $check( null !== $row && $row['content_hash'] === hash( 'sha256', $s ) && 'draft' === get_post_status( $id ), "{$role}: a draft linked to its brief with the sha256 of the content as stored" );
            $c = null === $row ? [] : $compile( $row['brief'] );
            $check( ! empty( $c['ok'] ) && LayoutGate::check( $s )->isValid(), "{$role}: the stored Lab page passes the layout gate and its brief compiles" );
            if ( null !== $row ) {
                update_post_meta( $id, '_aied_lr_original', wp_slash( $s ) );
                update_post_meta( $id, '_aied_lr_row', wp_slash( (string) wp_json_encode( $row ) ) );
            }
        };
        foreach ( AIED_LR_LAB as $role ) {
            $make( $role, $A );
        }
        $make( 'editor', $E );
        $info( 'ids', array_combine( array_merge( AIED_LR_LAB, [ 'editor' ] ), array_map( $find, array_merge( AIED_LR_LAB, [ 'editor' ] ) ) ) );
        $check( 7 === $userRows( $A ) && 1 === $userRows( $E ), 'the brief owner holds 7 Lab briefs and the editor 1', [ $userRows( $A ), $userRows( $E ) ] );
    }

    // =========================================================================================================================
    if ( 'restyle' === $stage ) {
        $R = $find( 'rest' );
        $M = $find( 'mcp' );
        $N = $find( 'denied' );
        $Q = $find( 'editor' );
        $check( $A > 0 && $E > 0 && $W > 0 && $R > 0 && $M > 0 && $N > 0 && $Q > 0, 'the users and the Lab pages exist (run build first)' );
        if ( $A > 0 && $E > 0 && $W > 0 && $R > 0 && $M > 0 && $N > 0 && $Q > 0 ) {
            wp_set_current_user( $A );
            $original = $stored( $R );
            $before   = $labRow( $A, $R );
            $base     = [ 'page_id' => $R, 'to_language' => $target[0], 'to_kit' => $target[1] ];
            $hist0    = count( HistoryStore::summaries( $R ) );

            // ---- 1. dry run ----
            $p   = $rest( AIED_LR_ROUTE, $base + [ 'dry_run' => true ] );
            $d   = $p['data'];
            $row = (array) ( $d['pages'][0] ?? [] );
            $check( 200 === $p['status'] && false === ( $d['changed'] ?? null ) && true === ( $d['dry_run'] ?? null ) && 1 === ( $d['would_change'] ?? null ) && 0 === ( $d['would_skip'] ?? null ), 'REST dry run: 200, changed false, one page would change, none skipped', $p['status'] . ' ' . wp_json_encode( array_intersect_key( $d, [ 'code' => 1, 'message' => 1, 'would_change' => 1, 'would_skip' => 1 ] ) ) );
            $check( true === ( $row['valid'] ?? null ) && [] === ( $row['violations'] ?? null ) && (int) ( $row['brief_id'] ?? 0 ) === (int) ( $before['id'] ?? -1 ), 'the preview says valid:true with no violation and names the page\'s brief' );
            $check( [ 'language' => 'editorial', 'kit' => 'quiet-solid' ] === ( $row['from'] ?? null ) && [ 'language' => $target[0], 'kit' => $target[1] ] === ( $row['to'] ?? null ), 'the preview reads the look from the brief (editorial + quiet-solid) and names the target (luxe + gilt-hairline)', [ $row['from'] ?? null, $row['to'] ?? null ] );
            $token = (string) ( $d['token'] ?? '' );
            $check( '' !== $token, 'a dry run returns the token' );
            $check( $stored( $R ) === $original && $labRow( $A, $R ) === $before && count( HistoryStore::summaries( $R ) ) === $hist0, 'the dry run left the page, its brief row and its history untouched' );
            $p2 = $rest( AIED_LR_ROUTE, $base + [ 'dry_run' => true ] );
            $check( $norm( $p2['data']['pages'] ?? null ) === $norm( $d['pages'] ?? null ), 'a second dry run gives the same pages (deterministic)' );

            // ---- 2. the token ----
            $none = $rest( AIED_LR_ROUTE, $base );
            $check( 400 === $none['status'], 'a real run without a token is refused (400)', $none['status'] );
            $bad = $rest( AIED_LR_ROUTE, $base + [ 'token' => 'not-a-token' ] );
            $check( 400 === $bad['status'], 'a made-up token is refused (400)', $bad['status'] );
            $other = $rest( AIED_LR_ROUTE, [ 'page_id' => $R, 'to_language' => 'glass-dark', 'to_kit' => 'glass-edge', 'token' => $token ] );
            $check( 400 === $other['status'], 'the token of one set of arguments does not work for other arguments (400)', $other['status'] );
            $deflt = $rest( AIED_LR_ROUTE, [ 'page_id' => $R, 'to_language' => $target[0], 'to_kit' => 'default', 'token' => $token ] );
            $check( 400 === $deflt['status'], 'to_kit "default" is another argument than the kit spelled out: the token is refused (400)', $deflt['status'] );
            $check( $stored( $R ) === $original && $labRow( $A, $R ) === $before, 'none of the refused runs changed the page or its brief' );

            // ---- 3. the reference and the real run ----
            $ref = $writeRef( 'ref-rest', $restyled( $before['brief'] ) );
            $r   = $rest( AIED_LR_ROUTE, $base + [ 'token' => $token ] );
            $rd  = $r['data'];
            $check( 200 === $r['status'] && true === ( $rd['changed'] ?? null ) && 1 === ( $rd['restyled'] ?? null ) && 0 === ( $rd['brief_not_updated'] ?? null ) && [] === ( $rd['skipped'] ?? null ), 'REST run with the token: changed, restyled 1, brief updated, nothing skipped', $r['status'] . ' ' . wp_json_encode( array_intersect_key( $rd, [ 'changed' => 1, 'restyled' => 1, 'skipped' => 1, 'message' => 1, 'brief_not_updated' => 1 ] ) ) );
            $batch = $addBatch( $R, $rd['batch'] ?? null );
            $check( $batch > 0 && true === ( $rd['batch']['undoable'] ?? null ), 'the run recorded one undoable batch', $rd['batch'] ?? null );
            $restyledRight( 'rest', $A, $R, $before, $ref );
            $hist = HistoryStore::summaries( $R );
            $check( count( $hist ) === $hist0 + 1 && 'lab_restyle' === ( $hist[0]['tool'] ?? null ), 'the previous content was snapshotted in the history by lab_restyle (one new entry)', array_slice( $hist, 0, 2 ) );
            $after = $stored( $R );
            $check( $after !== $original && ! str_contains( $after, '{{aied:' ) && ! str_contains( $after, '"aied":' ), 'the restyle really changed the page (the comparison is not trivial) and left no token or marker', $sha( $after ) . ' vs ' . $sha( $original ) );
            update_post_meta( $R, '_aied_lr_restyled', wp_slash( $after ) );
            update_post_meta( $R, '_aied_lr_restyled_row', wp_slash( (string) wp_json_encode( $labRow( $A, $R ) ) ) );
            $again = $rest( AIED_LR_ROUTE, $base + [ 'token' => $token ] );
            $check( 400 === $again['status'], 'the same token a second time is refused (single use)', $again['status'] );
            $check( $stored( $R ) === $after && count( HistoryStore::summaries( $R ) ) === $hist0 + 1, 'the refused second run changed nothing' );
            $same = $rest( AIED_LR_ROUTE, $base + [ 'dry_run' => true ] );
            $check( 0 === ( $same['data']['would_change'] ?? null ) && 'same_design' === ( $same['data']['pages'][0]['reason'] ?? null ), 'a preview of the same look on the restyled page: same_design, nothing would change', $same['data']['pages'][0] ?? $same['data'] );

            // ---- 4. MCP parity (signed in as the brief owner) ----
            $mBefore = $labRow( $A, $M );
            $list    = $mcpRpc( 'tools/list', [], $A );
            $names   = array_map( static fn ( $t ): string => (string) ( $t['name'] ?? '' ), (array) ( $list['result']['tools'] ?? [] ) );
            $check( in_array( LabRestyle::NAME, $names, true ), 'MCP tools/list names lab_restyle' );
            $mArgs = [ 'page_id' => $M, 'to_language' => $target[0], 'to_kit' => $target[1] ];
            $mp    = $mcpTool( LabRestyle::NAME, $mArgs + [ 'dry_run' => true ], $A );
            $check( ! $mp['isError'] && null === $mp['error'] && 1 === ( $mp['result']['would_change'] ?? null ), 'MCP dry run: not an error, one page would change', $mp['error'] ?? $mp['result'] );
            $check( $strip( $mp['result'] ) === $strip( $d ), 'the MCP preview payload equals the REST one (ids, titles and the token aside)', [ 'mcp' => $strip( $mp['result'] )['pages'][0] ?? null ] );
            $mref   = $writeRef( 'ref-mcp', $restyled( $mBefore['brief'] ) );
            $mtoken = (string) ( $mp['result']['token'] ?? '' );
            $mr     = $mcpTool( LabRestyle::NAME, $mArgs + [ 'token' => $mtoken ], $A );
            $check( ! $mr['isError'] && 1 === ( $mr['result']['restyled'] ?? null ) && true === ( $mr['result']['changed'] ?? null ), 'MCP run with the token: restyled 1', $mr['error'] ?? $mr['result'] );
            $addBatch( $M, $mr['result']['batch'] ?? null );
            $check( $strip( $mr['result'] ) === $strip( $rd ), 'the MCP run payload equals the REST one (ids, titles and the batch aside)', [ 'mcp' => $strip( $mr['result'] ), 'rest' => $strip( $rd ) ] );
            $restyledRight( 'mcp', $A, $M, $mBefore, $mref );
            $check( $stored( $M ) === $after, 'MCP and REST saved the same bytes (the two briefs differ only in their title)', $sha( $stored( $M ) ) . ' vs ' . $sha( $after ) );
            $mAgain = $mcpTool( LabRestyle::NAME, $mArgs + [ 'token' => $mtoken ], $A );
            $check( null !== $mAgain['error'] && -32602 === ( $mAgain['error']['code'] ?? null ), 'MCP: the same token again is refused (-32602)', $mAgain['error'] ?? null );
            $mBad = $mcpTool( LabRestyle::NAME, [ 'page_id' => 'abc', 'to_language' => 'luxe', 'dry_run' => true ], $A );
            $check( null !== $mBad['error'] && -32602 === ( $mBad['error']['code'] ?? null ), 'MCP: a page_id that is not a number is refused (-32602)', $mBad['error'] ?? null );

            // ---- 5. Lab still owns the page: the screen opens the brief, a rebuild overwrites in place ----
            $mRow           = $labRow( $A, $M );
            $_GET['brief']  = (string) (int) ( $mRow['id'] ?? 0 );
            $cfg            = StudioScreen::config( $A, false );
            unset( $_GET['brief'] );
            $check( is_array( $cfg['brief'] ?? null ) && (int) $cfg['brief']['id'] === (int) $mRow['id'] && $target[0] === ( $cfg['brief']['brief']['language'] ?? null ) && $target[1] === ( $cfg['brief']['brief']['kit'] ?? null ) && $M === $cfg['brief']['page_id'] && $mRow['brief_hash'] === $cfg['brief']['hash'], 'the Lab screen opens the restyled brief (new look, linked page, current hash)', is_array( $cfg['brief'] ?? null ) ? array_intersect_key( $cfg['brief'], [ 'id' => 1, 'page_id' => 1 ] ) : $cfg['brief'] ?? null );
            $mStored = $stored( $M );
            $rb      = StudioService::rebuild( $A, (int) $mRow['id'] );
            $check( true === $rb['ok'] && StudioService::MODE_OVERWRITTEN === $rb['mode'] && $M === $rb['page_id'] && null === $rb['reason'], 'Lab\'s rebuild of the restyled page: overwritten in place (the page is still Lab\'s)', array_intersect_key( $rb, [ 'ok' => 1, 'code' => 1, 'mode' => 1, 'reason' => 1, 'page_id' => 1 ] ) );
            $check( $stored( $M ) === $mStored, 'the rebuild wrote the same bytes (the restyle is exactly what Lab builds from the brief now)', $sha( $stored( $M ) ) . ' vs ' . $sha( $mStored ) );

            // ---- 6. permissions ----
            wp_set_current_user( $W );
            $author = $rest( AIED_LR_ROUTE, [ 'page_id' => $N, 'to_language' => $target[0], 'dry_run' => true ] );
            $check( 403 === $author['status'], 'a user without edit_pages (an author) is refused (403)', $author['status'] );

            wp_set_current_user( $A );
            $ownerPreview = $rest( AIED_LR_ROUTE, [ 'page_id' => $N, 'to_language' => $target[0], 'to_kit' => $target[1], 'dry_run' => true ] );
            $ownerToken   = (string) ( $ownerPreview['data']['token'] ?? '' );
            $nBefore      = $stored( $N );
            $nRow         = $labRow( $A, $N );
            wp_set_current_user( $E );
            $ed = $rest( AIED_LR_ROUTE, [ 'page_id' => $N, 'to_language' => $target[0], 'to_kit' => $target[1], 'dry_run' => true ] );
            $check( 200 === $ed['status'] && 0 === ( $ed['data']['would_change'] ?? null ) && 'not_lab_page' === ( $ed['data']['pages'][0]['reason'] ?? null ) && empty( $ed['data']['token'] ),'an editor who can edit the owner\'s Lab page still cannot restyle it: not_lab_page (the brief is the owner\'s), no token', $ed['data']['pages'][0] ?? $ed['data'] );
            $deny = static function ( array $caps, string $cap, int $u, array $a ) use ( $N ): array {
                return 'edit_post' === $cap && (int) ( $a[0] ?? 0 ) === $N ? [ 'do_not_allow' ] : $caps;
            };
            add_filter( 'map_meta_cap', $deny, 99, 4 );
            try {
                $denied = $rest( AIED_LR_ROUTE, [ 'page_id' => $N, 'to_language' => $target[0], 'to_kit' => $target[1], 'dry_run' => true ] );
                $check( 200 === $denied['status'] && 0 === ( $denied['data']['would_change'] ?? null ) && empty( $denied['data']['token'] ) && [ 'id' => $N, 'reason' => 'forbidden' ] === ( $denied['data']['pages'][0] ?? null ), 'an editor without edit_post on the page: one bare {id, reason: forbidden} row (no title, no brief), would_change 0, no token', $denied['data']['pages'][0] ?? $denied['data'] );
                $stolen = '' === $ownerToken ? [ 'status' => 0 ] : $rest( AIED_LR_ROUTE, [ 'page_id' => $N, 'to_language' => $target[0], 'to_kit' => $target[1], 'token' => $ownerToken ] );
                $check( '' !== $ownerToken && 400 === $stolen['status'], 'the owner\'s token is refused for the editor (400)', $stolen['status'] );
            } finally {
                remove_filter( 'map_meta_cap', $deny, 99 );
            }
            $own = $rest( AIED_LR_ROUTE, [ 'page_id' => $Q, 'to_language' => $target[0], 'to_kit' => $target[1], 'dry_run' => true ] );
            $check( 200 === $own['status'] && 1 === ( $own['data']['would_change'] ?? null ) && '' !== (string) ( $own['data']['token'] ?? '' ), 'the editor may preview a restyle of their own Lab page (a token)', $own['data']['pages'][0] ?? $own['data'] );
            $all = $rest( AIED_LR_ROUTE, [ 'all_mine' => true, 'to_language' => $target[0], 'to_kit' => $target[1], 'dry_run' => true ] );
            $check( 200 === $all['status'] && [ $Q ] === array_map( static fn ( $x ): int => (int) ( $x['id'] ?? 0 ), (array) ( $all['data']['pages'] ?? [] ) ), 'all_mine as the editor lists only the editor\'s own Lab page', array_map( static fn ( $x ) => $x['id'] ?? null, (array) ( $all['data']['pages'] ?? [] ) ) );
            wp_set_current_user( $A );
            $check( $stored( $N ) === $nBefore && $labRow( $A, $N ) === $nRow, 'the owner\'s page and brief were not changed by any of this' );
            $info( 'batches', [ 'rest' => $batch ] );
        }
    }

    // =========================================================================================================================
    if ( 'changed' === $stage ) {
        $X = $find( 'changed' );
        $B = $find( 'briefedit' );
        $check( $A > 0 && $X > 0 && $B > 0, 'the pages for the changed-since-preview checks exist (run build first)' );
        if ( $A > 0 && $X > 0 && $B > 0 ) {
            wp_set_current_user( $A );
            // A page edited (as the builder saves it) between the preview and the run.
            $args = [ 'page_id' => $X, 'to_language' => $target[0], 'to_kit' => $target[1] ];
            $xRow = $labRow( $A, $X );
            $p    = $rest( AIED_LR_ROUTE, $args + [ 'dry_run' => true ] );
            $check( 200 === $p['status'] && 1 === ( $p['data']['would_change'] ?? null ) && '' !== (string) ( $p['data']['token'] ?? '' ), 'the preview of the page to be edited offers a token' );
            $edited = str_replace( 'Questions, answered', 'Questions, edited in the builder', $stored( $X ) );
            $check( $edited !== $stored( $X ), 'the edit changes the page text' );
            wp_update_post( wp_slash( [ 'ID' => $X, 'post_content' => $edited ] ) );
            $edited = $stored( $X );
            $r      = $rest( AIED_LR_ROUTE, $args + [ 'token' => (string) ( $p['data']['token'] ?? '' ) ] );
            $reason = (string) ( $r['data']['skipped'][0]['reason'] ?? '' );
            $check( 200 === $r['status'] && false === ( $r['data']['changed'] ?? null ) && 0 === ( $r['data']['restyled'] ?? -1 ) && ! isset( $r['data']['batch'] ), 'the run restyled nothing and recorded no batch', $r['data'] );
            $check( in_array( $reason, [ 'edited', 'changed_since_preview' ], true ), "the edited page is skipped (reason: {$reason})", $r['data']['skipped'] ?? null );
            $check( $stored( $X ) === $edited && $labRow( $A, $X ) === $xRow, 'the edited page was NOT overwritten (byte for byte) and its brief row is unchanged' );

            // A page whose brief was edited (in the Lab screen) between the preview and the run.
            $args = [ 'page_id' => $B, 'to_language' => $target[0], 'to_kit' => $target[1] ];
            $bRow = $labRow( $A, $B );
            $bPg  = $stored( $B );
            $p    = $rest( AIED_LR_ROUTE, $args + [ 'dry_run' => true ] );
            $check( 200 === $p['status'] && 1 === ( $p['data']['would_change'] ?? null ) && '' !== (string) ( $p['data']['token'] ?? '' ), 'the preview of the page whose brief will be edited offers a token' );
            $newBrief = $bRow['brief'];
            $newBrief['sections'][0]['slots']['heading.1'] = 'Calm rooms, edited in Lab';
            $saved = StudioStore::save( $A, $newBrief, (int) $bRow['id'], (string) $bRow['brief_hash'] );
            $check( true === $saved['ok'], 'the brief was edited after the preview (compare-and-set save)', $saved );
            $editedRow = $labRow( $A, $B );
            $r         = $rest( AIED_LR_ROUTE, $args + [ 'token' => (string) ( $p['data']['token'] ?? '' ) ] );
            $check( 200 === $r['status'] && 0 === ( $r['data']['restyled'] ?? -1 ) && 'changed_since_preview' === ( $r['data']['skipped'][0]['reason'] ?? null ), 'the page whose brief changed is skipped with reason changed_since_preview', $r['data']['skipped'] ?? $r['data'] );
            $check( $stored( $B ) === $bPg && $labRow( $A, $B ) === $editedRow, 'the page was NOT overwritten and the edited brief was NOT rolled over' );
        }
    }

    // =========================================================================================================================
    if ( 'published' === $stage ) {
        $P = $find( 'published' );
        $check( $A > 0 && $P > 0, 'the page for the published check exists (run build first)' );
        if ( $A > 0 && $P > 0 ) {
            wp_set_current_user( $A );
            $before = $labRow( $A, $P );
            $pg     = $stored( $P );
            wp_update_post( [ 'ID' => $P, 'post_status' => 'publish' ] );
            $check( 'publish' === $status( $P ) && $stored( $P ) === $pg, 'the Lab page was published (content unchanged)' );
            $args = [ 'page_id' => $P, 'to_language' => $target[0], 'to_kit' => $target[1] ];
            $no   = $rest( AIED_LR_ROUTE, $args + [ 'dry_run' => true ] );
            $check( 200 === $no['status'] && 0 === ( $no['data']['would_change'] ?? null ) && 'published' === ( $no['data']['pages'][0]['reason'] ?? null ) && empty( $no['data']['token'] ), 'without include_published the published page is skipped (published), no token', $no['data']['pages'][0] ?? $no['data'] );
            $yes = $rest( AIED_LR_ROUTE, $args + [ 'include_published' => true, 'dry_run' => true ] );
            $check( 200 === $yes['status'] && 1 === ( $yes['data']['would_change'] ?? null ) && '' !== (string) ( $yes['data']['token'] ?? '' ), 'with include_published it would be restyled (a token)', $yes['data']['pages'][0] ?? $yes['data'] );
            $ref = $writeRef( 'ref-published', $restyled( $before['brief'] ) );
            $r   = $rest( AIED_LR_ROUTE, $args + [ 'include_published' => true, 'token' => (string) ( $yes['data']['token'] ?? '' ) ] );
            $check( 200 === $r['status'] && 1 === ( $r['data']['restyled'] ?? null ), 'the run with include_published restyled the published page', $r['data'] );
            $addBatch( $P, $r['data']['batch'] ?? null );
            $restyledRight( 'published', $A, $P, $before, $ref );
            $check( 'publish' === $status( $P ), 'the restyled page is still published' );
        }
    }

    // =========================================================================================================================
    if ( 'kses' === $stage ) {
        $K = $find( 'kses' );
        $check( $A > 0 && $K > 0, 'the page for the kses check exists (run build first)' );
        if ( $A > 0 && $K > 0 ) {
            wp_set_current_user( $A );
            $before = $labRow( $A, $K );
            $noRaw  = static function ( array $caps, string $cap, int $u ) use ( $A ): array {
                return 'unfiltered_html' === $cap && $u === $A ? [ 'do_not_allow' ] : $caps;
            };
            add_filter( 'map_meta_cap', $noRaw, 99, 3 );
            kses_init(); // the content filters follow the current user's unfiltered_html
            try {
                $check( ! current_user_can( 'unfiltered_html' ) && current_user_can( 'edit_pages' ) && false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'the brief owner has no unfiltered_html now and kses is on for its saves' );
                $ref  = $writeRef( 'ref-kses', $restyled( $before['brief'] ) );
                $args = [ 'page_id' => $K, 'to_language' => $target[0], 'to_kit' => $target[1] ];
                $p    = $rest( AIED_LR_ROUTE, $args + [ 'dry_run' => true ] );
                $check( 1 === ( $p['data']['would_change'] ?? null ), 'the preview without unfiltered_html: one page would change', $p['data']['pages'][0] ?? $p['data'] );
                $r = $rest( AIED_LR_ROUTE, $args + [ 'token' => (string) ( $p['data']['token'] ?? '' ) ] );
                $check( 200 === $r['status'] && 1 === ( $r['data']['restyled'] ?? null ) && 0 === ( $r['data']['brief_not_updated'] ?? null ), 'the restyle without unfiltered_html saved the page and its brief', $r['data'] );
                $addBatch( $K, $r['data']['batch'] ?? null );
            } finally {
                remove_filter( 'map_meta_cap', $noRaw, 99 );
                kses_init();
            }
            $now = $stored( $K );
            $check( ! str_contains( $now, '&lt;!-- wp:' ) && ! str_contains( $now, '&lt;/' ) && ! str_contains( $now, '&lt;div' ) && ! str_contains( $now, '&lt;p' ), 'the stored page has no visible block or tag code (no &lt;!-- wp:, &lt;/, &lt;div, &lt;p)' );
            $restyledRight( 'kses', $A, $K, $before, $ref );
        }
    }

    // =========================================================================================================================
    if ( 'undo' === $stage ) {
        $R = $find( 'rest' );
        $check( $A > 0 && $R > 0, 'the restyled page exists (run build and restyle first)' );
        if ( $A > 0 && $R > 0 ) {
            $original    = (string) get_post_meta( $R, '_aied_lr_original', true );
            $origRow     = json_decode( (string) get_post_meta( $R, '_aied_lr_row', true ), true );
            $restyledPg  = (string) get_post_meta( $R, '_aied_lr_restyled', true );
            $restyledRow = json_decode( (string) get_post_meta( $R, '_aied_lr_restyled_row', true ), true );
            $batchIds    = array_map( 'intval', (array) get_post_meta( $R, '_aied_lr_batch', false ) );
            $batchId     = (int) ( $batchIds[0] ?? 0 );
            $check( '' !== $original && is_array( $origRow ) && '' !== $restyledPg && is_array( $restyledRow ) && $batchId > 0, 'the original and restyled page and brief row and the batch id were recorded by the earlier stages' );
            $check( $stored( $R ) === $restyledPg && $labRow( $A, $R ) === $restyledRow, 'before the undo the page and its brief are the restyled ones' );
            if ( '' !== $original && is_array( $origRow ) && $batchId > 0 ) {
                $briefId = (int) $origRow['id'];
                $mine0   = $userRows( 1 );
                wp_set_current_user( 1 );
                $u = $rest( '/pro/undo_batch', [ 'batch_id' => $batchId ] );
                $check( 200 === $u['status'] && true === ( $u['data']['fully_undone'] ?? null ) && 2 === ( $u['data']['restored'] ?? null ), 'undo_batch (by user 1, not the brief owner): fully undone, two items restored (page and brief)', $u['status'] . ' ' . wp_json_encode( array_intersect_key( $u['data'], [ 'fully_undone' => 1, 'restored' => 1, 'message' => 1 ] ) ) );
                $undoId = $addBatch( $R, $u['data']['undo_batch'] ?? null );
                $check( $stored( $R ) === $original, 'the undo restored the original page byte for byte', $sha( $stored( $R ) ) . ' vs ' . $sha( $original ) );
                $check( $rowOf( $A, $briefId ) === $origRow, 'the undo restored the brief row exactly (brief, brief hash, page, content hash)', $rowOf( $A, $briefId ) );
                $check( $userRows( 1 ) === $mine0, 'the undo made no brief row of its own for the user who ran it' );

                wp_set_current_user( $A );
                $rb = StudioService::rebuild( $A, $briefId );
                $check( true === $rb['ok'] && StudioService::MODE_OVERWRITTEN === $rb['mode'] && $R === $rb['page_id'], 'after the undo Lab\'s rebuild overwrites the page in place (Lab still owns it)', array_intersect_key( $rb, [ 'ok' => 1, 'code' => 1, 'mode' => 1, 'reason' => 1, 'page_id' => 1 ] ) );
                $check( $stored( $R ) === $original, 'that rebuild wrote the original bytes again (the brief is the original one)', $sha( $stored( $R ) ) . ' vs ' . $sha( $original ) );

                wp_set_current_user( 1 );
                $uu = $undoId > 0 ? $rest( '/pro/undo_batch', [ 'batch_id' => $undoId ] ) : [ 'status' => 0, 'data' => [] ];
                $check( 200 === $uu['status'] && true === ( $uu['data']['fully_undone'] ?? null ), 'undo of the undo: fully undone', $uu['status'] . ' ' . wp_json_encode( array_intersect_key( $uu['data'], [ 'fully_undone' => 1, 'restored' => 1, 'message' => 1, 'items' => 1 ] ) ) );
                $addBatch( $R, $uu['data']['undo_batch'] ?? null );
                $check( $stored( $R ) === $restyledPg, 'undo of the undo brought the restyled page back byte for byte', $sha( $stored( $R ) ) . ' vs ' . $sha( $restyledPg ) );
                $check( $rowOf( $A, $briefId ) === $restyledRow, 'undo of the undo brought the restyled brief row back exactly', $rowOf( $A, $briefId ) );

                wp_set_current_user( $A );
                $rb2 = StudioService::rebuild( $A, $briefId );
                $check( true === $rb2['ok'] && StudioService::MODE_OVERWRITTEN === $rb2['mode'] && $R === $rb2['page_id'] && $stored( $R ) === $restyledPg, 'after the undo of the undo Lab\'s rebuild still overwrites in place with the same bytes', array_intersect_key( $rb2, [ 'ok' => 1, 'code' => 1, 'mode' => 1, 'reason' => 1, 'page_id' => 1 ] ) );
            }
        }
    }

    // =========================================================================================================================
    if ( 'cleanup' === $stage ) {
        $prefix  = 'all' === $run ? AIED_LR_TITLE : AIED_LR_TITLE . $run . ' ';
        $ids     = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ) ); // phpcs:ignore
        $batches = [];
        foreach ( $ids as $id ) {
            foreach ( (array) get_post_meta( $id, '_aied_lr_batch', false ) as $b ) {
                if ( (int) $b > 0 ) {
                    $batches[] = (int) $b;
                }
            }
        }
        $batches = array_values( array_unique( $batches ) );
        foreach ( $ids as $id ) {
            $p = get_post( $id );
            if ( $p && str_starts_with( (string) $p->post_title, $prefix ) ) { // never a page without the prefix
                wp_delete_post( $id, true );
            }
        }
        if ( [] !== $batches && BatchTable::exists() ) {
            $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', $batches ) . ')' ); // phpcs:ignore
        }
        $logins = (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'all' === $run ? AIED_LR_USER : AIED_LR_USER . $run . '_' ) . '%' ) ); // phpcs:ignore
        $uids   = [];
        foreach ( $logins as $login ) {
            $u = get_user_by( 'login', (string) $login );
            if ( $u instanceof WP_User && str_starts_with( $u->user_login, AIED_LR_USER ) && (int) $u->ID > 1 ) {
                $uids[] = (int) $u->ID;
            }
        }
        if ( [] !== $uids && StudioTable::exists() ) {
            $wpdb->query( 'DELETE FROM ' . StudioTable::name() . ' WHERE created_by IN (' . implode( ',', $uids ) . ')' ); // phpcs:ignore
        }
        foreach ( $uids as $uid ) {
            wp_delete_user( $uid );
        }
        $left        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore
        $leftUsers   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'all' === $run ? AIED_LR_USER : AIED_LR_USER . $run . '_' ) . '%' ) ); // phpcs:ignore
        $leftRows    = [] === $uids || ! StudioTable::exists() ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() . ' WHERE created_by IN (' . implode( ',', $uids ) . ')' ); // phpcs:ignore
        $leftBriefs  = StudioTable::exists() ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . StudioTable::name() . ' WHERE brief LIKE %s', '%' . $wpdb->esc_like( '"title":"' . rtrim( $prefix ) ) . '%' ) ) : 0; // phpcs:ignore
        $leftBatches = [] === $batches || ! BatchTable::exists() ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', $batches ) . ')' ); // phpcs:ignore
        $leftHistory = 0;
        foreach ( $ids as $id ) {
            $leftHistory += count( HistoryStore::summaries( $id ) );
        }
        $check( 0 === $left, 'no `aied labrestyle ` post remains (deleted ' . count( $ids ) . ')', $left );
        $check( 0 === $leftUsers, 'no throwaway user remains (' . count( $uids ) . ' removed)' );
        $check( 0 === $leftRows && 0 === $leftBriefs, 'no Lab brief row of this gate remains', [ 'by_user' => $leftRows, 'by_title' => $leftBriefs ] );
        $check( 0 === $leftBatches, 'no batch row of this gate remains (' . count( $batches ) . ' removed)' );
        $check( 0 === $leftHistory, 'no history row of the deleted pages remains' );
    }
} catch ( \Throwable $e ) {
    $check( false, "stage {$stage} threw", $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
    wp_set_current_user( 1 );
}

$emit( [ 'type' => 'info', 'key' => 'stage_failures', 'value' => count( $failures ) ] );
if ( [] !== $failures ) {
    WP_CLI::halt( 1 );
}
