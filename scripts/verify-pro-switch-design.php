<?php
/**
 * Real-site check of the Pro tool switch_design (design switch plan Task 9, the PHP half; the orchestrator and the render comparison are
 * scripts/verify-pro-switch-design.cjs, `make verify-pro-switch-design`). One stage per wp eval-file call:
 *
 *   build <runToken>    six recipes of the clean matrix-1 set (hero-cta, section-intro, blurb-grid, stats-counter, faq-accordion,
 *                       cta-split-form) are served by the free pipeline (SectionRecipes::recipe) under editorial + quiet-solid and
 *                       under luxe + gilt-hairline, wrapped in the placeholder block, gated by LayoutGate and saved (wp_slash) as
 *                       throwaway drafts titled `aied switch <runToken> source|direct|mcp|changed|denied` (source, mcp, changed and
 *                       denied are the same page under the source design, direct is the target design built directly)
 *   switch <runToken>   dry run (REST): nothing changes, valid, one page would change, the source design is detected, the report equals the
 *                       engine's own and is the same on a second run; "default" kit resolves; a real run needs the token (none, a made-up one);
 *                       the real run saves EXACTLY the direct page (byte for byte), snapshots it through the history and records one batch;
 *                       the same token a second time is refused and changes nothing; the MCP handler (tools/list, then dry run and run on
 *                       another copy) gives the same report and the same saved bytes, and refuses a bad argument; a user without
 *                       edit_pages is refused (403); an editor without edit_post on the page gets a bare `forbidden` row and no token, and
 *                       another user's token is refused
 *   changed <runToken>  a page edited between the preview and the run is skipped (changed_since_preview) and never overwritten
 *   undo <runToken>     undo_batch restores the switched page byte for byte
 *   cleanup <runToken|all>  deletes the pages (`all`: every `aied switch ` page), the throwaway user and the batch rows this gate recorded
 *
 * Output: JSON lines on stdout, { "type": "check", "ok": bool, "label": ..., "detail": ... } per check and { "type": "info", ... }; the exit
 * status is 1 when a check failed. A FAIL is a finding about the tool: report it, never edit this script to hide it.
 *
 * Needs `make up`, the free plugin and the Pro add-on active on the dev site (--user=1, an administrator). No licence is needed: the registry
 * is built with the unlock forced, as the other verify-pro-* gates do. Dev site only. It records two batches (the store keeps the newest
 * 20: older ones may be evicted on a site that already has many); it deletes the batch rows it made.
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\SwitchDesign;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\SectionRecipes;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( SwitchDesign::class ) || ! class_exists( SectionRecipes::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro (with switch_design) on this site first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
require_once ABSPATH . 'wp-admin/includes/user.php';

const AIED_SW_TITLE   = 'aied switch ';
const AIED_SW_USER    = 'aied_sw_ed_';
const AIED_SW_RECIPES = [ 'hero-cta', 'section-intro', 'blurb-grid', 'stats-counter', 'faq-accordion', 'cta-split-form' ];
const AIED_SW_ROLES   = [ 'source', 'direct', 'mcp', 'changed', 'denied' ];

$stage = (string) ( $args[0] ?? '' );
$run   = (string) ( $args[1] ?? '' );
if ( ! in_array( $stage, [ 'build', 'switch', 'changed', 'undo', 'cleanup' ], true ) ) {
    WP_CLI::error( 'usage: wp eval-file verify-pro-switch-design.php <build|switch|changed|undo|cleanup> <runToken|all>' );
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
    $emit( [ 'type' => 'check', 'ok' => $ok, 'label' => $label, 'detail' => is_string( $detail ) ? $detail : ( null === $detail ? null : substr( (string) wp_json_encode( $detail ), 0, 400 ) ) ] );
};
$info = static function ( string $key, mixed $value ) use ( $emit ): void {
    $emit( [ 'type' => 'info', 'key' => $key, 'value' => $value ] );
};

// ---- helpers ----------------------------------------------------------------------------------------------------------------
/** The six recipes under one design, as the free plugin serves them, wrapped in the placeholder block. Null when a recipe does not resolve. */
$build = static function ( string $language, string $kit, array $recipes = AIED_SW_RECIPES ): ?string {
    $body = '';
    foreach ( $recipes as $name ) {
        $m = SectionRecipes::recipe( $name, $language, $kit );
        if ( null === $m ) {
            return null;
        }
        $body .= $m;
    }

    return '<!-- wp:divi/placeholder -->' . $body . '<!-- /wp:divi/placeholder -->';
};
$find = static function ( string $role ) use ( $run, $wpdb ): int {
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title = %s ORDER BY ID DESC LIMIT 1", AIED_SW_TITLE . $run . ' ' . $role ) ); // phpcs:ignore
};
$stored = static function ( int $id ): string {
    clean_post_cache( $id );
    $p = get_post( $id );

    return $p ? (string) $p->post_content : '';
};
$modified = static function ( int $id ): string {
    clean_post_cache( $id );
    $p = get_post( $id );

    return $p ? (string) $p->post_modified_gmt : '';
};
$history = static fn ( int $id ): array => HistoryStore::summaries( $id );
$norm    = static fn ( mixed $v ): mixed => json_decode( (string) wp_json_encode( $v ), true );
$sha     = static fn ( string $s ): string => substr( sha1( $s ), 0, 12 ) . '/' . strlen( $s );

$ns   = '/ai-editor-divi5/v1';
$rest = static function ( string $route, array $body ) use ( $ns ): array {
    $req = new WP_REST_Request( 'POST', $ns . $route );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};
/** The free MCP endpoint as an assistant calls it: Bearer API key, JSON-RPC. Returns [ 'error' => ?array, 'result' => the decoded tool payload or the raw result ]. */
$mcpRpc = static function ( string $method, array $params ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    try {
        $res = rest_do_request( $req );
    } finally {
        unset( $_SERVER['HTTP_AUTHORIZATION'] );
        wp_set_current_user( 1 ); // authenticateRequest() switched to the key owner; back to --user=1
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => (array) $data['error'], 'result' => null ];
    }

    return [ 'error' => null, 'result' => (array) ( $data['result'] ?? [] ) ];
};
$mcpTool = static function ( string $tool, array $arguments ) use ( $mcpRpc ): array {
    $r = $mcpRpc( 'tools/call', [ 'name' => $tool, 'arguments' => $arguments ] );
    if ( null !== $r['error'] ) {
        return [ 'error' => $r['error'], 'isError' => true, 'result' => null ];
    }
    $text = (string) ( $r['result']['content'][0]['text'] ?? '' );

    return [ 'error' => null, 'isError' => (bool) ( $r['result']['isError'] ?? false ), 'result' => json_decode( $text, true ) ];
};

$deleteUser = static function () use ( $run ): void {
    $u = get_user_by( 'login', AIED_SW_USER . $run );
    if ( $u instanceof WP_User && AIED_SW_USER . $run === $u->user_login && (int) $u->ID > 1 ) {
        wp_delete_user( (int) $u->ID );
    }
};

// The tools are served as the add-on serves them when licensed: unlock forced, routes registered, MCP hooks added.
if ( in_array( $stage, [ 'switch', 'changed', 'undo' ], true ) ) {
    $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
    $registry->register();
    ( new ProRest( $registry ) )->register();
    $GLOBALS['wp_rest_server'] = null; // WP-CLI built the server before these hooks existed
}

wp_set_current_user( 1 );
$sourceDesign = [ 'editorial', 'quiet-solid' ];
$targetDesign = [ 'luxe', 'gilt-hairline' ];

try {
    // =========================================================================================================================
    if ( 'build' === $stage ) {
        $tool = ( new ToolRegistry( Tools::all(), static fn (): bool => true ) )->get( SwitchDesign::NAME );
        $check( $tool instanceof SwitchDesign, 'the switch_design tool is registered' );
        $check( $tool instanceof SwitchDesign && null === $tool->unsupported(), 'the installed free plugin supports the switch (no "update the free plugin" refusal)', $tool instanceof SwitchDesign ? $tool->unsupported() : null );

        $src = $build( $sourceDesign[0], $sourceDesign[1] );
        $dst = $build( $targetDesign[0], $targetDesign[1] );
        $check( null !== $src && null !== $dst, 'the six recipes resolve under the source and under the target design' );
        if ( null !== $src && null !== $dst ) {
            $check( $src !== $dst, 'the source and the direct page differ (the comparison is not trivial)' );
            $check( LayoutGate::check( $src )->isValid() && LayoutGate::check( $dst )->isValid(), 'both pages pass the layout gate' );
            $check( ! str_contains( $src, '{{aied:' ) && ! str_contains( $dst, '{{aied:' ) && ! str_contains( $src, '"aied":' ) && ! str_contains( $dst, '"aied":' ), 'no token or marker is left in either page' );
            foreach ( AIED_SW_ROLES as $role ) {
                $content = 'direct' === $role ? $dst : $src;
                $id      = wp_insert_post( wp_slash( [
                    'post_type'    => 'page',
                    'post_status'  => 'draft',
                    'post_title'   => AIED_SW_TITLE . $run . ' ' . $role,
                    'post_content' => $content,
                    'meta_input'   => [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ],
                ] ), true );
                if ( is_wp_error( $id ) ) {
                    $check( false, "page {$role} saved", $id->get_error_message() );
                    continue;
                }
                $check( $stored( (int) $id ) === $content, "page {$role} is stored byte for byte as built (id {$id})" );
                if ( 'source' === $role ) {
                    update_post_meta( (int) $id, '_aied_switch_original', wp_slash( $content ) );
                }
            }
            $info( 'ids', [ 'source' => $find( 'source' ), 'direct' => $find( 'direct' ), 'mcp' => $find( 'mcp' ), 'changed' => $find( 'changed' ), 'denied' => $find( 'denied' ) ] );
            $info( 'bytes', [ 'source' => strlen( $src ), 'direct' => strlen( $dst ) ] );
        }
    }

    // =========================================================================================================================
    if ( 'switch' === $stage ) {
        $S = $find( 'source' );
        $D = $find( 'direct' );
        $M = $find( 'mcp' );
        $X = $find( 'changed' );
        $N = $find( 'denied' );
        $check( $S > 0 && $D > 0 && $M > 0 && $X > 0 && $N > 0, 'the five throwaway pages exist (run build first)' );
        if ( $S > 0 && $D > 0 && $M > 0 && $X > 0 && $N > 0 ) {
            $original = $stored( $S );
            $direct   = $stored( $D );
            $base     = [ 'page_id' => $S, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1] ];
            $route    = '/pro/switch_design';

            // ---- what the engine itself says (the independent expectation) ----
            $engine = DesignSwitch::run( $original, Design::make( $sourceDesign[0], $sourceDesign[1] ), Design::make( $targetDesign[0], $targetDesign[1] ), 'leave' );
            $check( null === $engine->error && $engine->changed && $engine->recovered() > 0, 'the engine alone switches the page (changed, something recovered)', $engine->error );
            $check( $engine->content === $direct, 'the engine alone gives exactly the direct page, byte for byte', $sha( $engine->content ) . ' vs ' . $sha( $direct ) );
            $expected = $norm( SwitchDesign::report( $engine ) );
            $info( 'engine_report', [ 'inspected' => $engine->inspected, 'switched' => $engine->switched, 'left_ambiguous' => count( $engine->left['ambiguous'] ), 'left_drifted' => count( $engine->left['drifted'] ), 'assumed' => count( $engine->assumed ) ] );

            // ---- 1. dry run ----
            $hist0 = count( $history( $S ) );
            $mod0  = $modified( $S );
            sleep( 1 ); // post_modified has one-second resolution
            $p = $rest( $route, $base + [ 'dry_run' => true ] );
            $d = $p['data'];
            $row = (array) ( $d['pages'][0] ?? [] );
            $check( 200 === $p['status'] && false === ( $d['changed'] ?? null ) && true === ( $d['dry_run'] ?? null ), 'REST dry run: 200, changed false, dry_run true', $p['status'] . ' ' . wp_json_encode( array_intersect_key( $d, [ 'code' => 1, 'message' => 1 ] ) ) );
            $check( 1 === ( $d['would_change'] ?? null ) && 0 === ( $d['would_skip'] ?? null ) && 1 === count( (array) ( $d['pages'] ?? [] ) ), 'the preview: one page would change, none skipped', array_intersect_key( $d, [ 'would_change' => 1, 'would_skip' => 1 ] ) );
            $check( true === ( $row['valid'] ?? null ) && [] === ( $row['violations'] ?? null ), 'the preview says valid:true with no violation' );
            $check( 'editorial' === ( $row['from']['language'] ?? null ) && 'quiet-solid' === ( $row['from']['kit'] ?? null ) && 'detected' === ( $row['from']['source']['language'] ?? null ), 'the source design is detected as editorial + quiet-solid', $row['from'] ?? null );
            $check( 'luxe' === ( $row['to']['language'] ?? null ) && 'gilt-hairline' === ( $row['to']['kit'] ?? null ), 'the target design is luxe + gilt-hairline', $row['to'] ?? null );
            $check( 0 === ( $row['report']['left']['ambiguous_count'] ?? -1 ) && 0 === ( $row['report']['left']['drifted_count'] ?? -1 ), 'nothing ambiguous and nothing drifted in the report' );
            $check( $norm( $row['report'] ?? null ) === $expected, 'the previewed report equals the engine\'s own (the switched counts)', [ 'tool' => $row['report']['switched'] ?? null, 'engine' => $expected['switched'] ?? null ] );
            $token = (string) ( $d['token'] ?? '' );
            $check( '' !== $token, 'a dry run returns the token' );
            $check( $stored( $S ) === $original && $modified( $S ) === $mod0 && count( $history( $S ) ) === $hist0, 'the dry run left the page, its post_modified and its history untouched' );
            $p2 = $rest( $route, $base + [ 'dry_run' => true ] );
            $check( $norm( $p2['data']['pages'] ?? null ) === $norm( $d['pages'] ?? null ), 'a second dry run gives the same pages (deterministic)' );
            $own = $rest( $route, [ 'page_id' => $S, 'to_language' => $targetDesign[0], 'to_kit' => 'default', 'dry_run' => true ] );
            $check( 200 === $own['status'] && 'gilt-hairline' === ( $own['data']['pages'][0]['to']['kit'] ?? null ), 'to_kit "default" resolves to the target language\'s own kit (gilt-hairline)', $own['data']['pages'][0]['to'] ?? $own['data'] );

            // ---- 2. the token ----
            $none = $rest( $route, $base );
            $check( 400 === $none['status'] && 'invalid_argument' === ( $none['data']['code'] ?? null ), 'a real run without a token is refused (400)', $none['status'] );
            $bad = $rest( $route, $base + [ 'token' => 'not-a-token' ] );
            $check( 400 === $bad['status'], 'a made-up token is refused (400)', $bad['status'] );
            $other = $rest( $route, [ 'page_id' => $S, 'to_language' => 'glass-dark', 'to_kit' => 'glass-edge', 'token' => $token ] );
            $check( 400 === $other['status'], 'the token of one set of arguments does not work for other arguments (400)', $other['status'] );
            $check( $stored( $S ) === $original, 'none of the refused runs changed the page' );

            // ---- 3. the real run ----
            $r  = $rest( $route, $base + [ 'token' => $token ] );
            $rd = $r['data'];
            $check( 200 === $r['status'] && true === ( $rd['changed'] ?? null ) && 1 === ( $rd['switched'] ?? null ) && [] === ( $rd['skipped'] ?? null ), 'REST run with the token: changed, switched 1, nothing skipped', $r['status'] . ' ' . wp_json_encode( array_intersect_key( $rd, [ 'changed' => 1, 'switched' => 1, 'skipped' => 1, 'message' => 1 ] ) ) );
            $check( $stored( $S ) === $direct, 'the stored switched page EQUALS the directly built page, byte for byte', $sha( $stored( $S ) ) . ' vs ' . $sha( $direct ) );
            $batchId = (int) ( $rd['batch']['id'] ?? 0 );
            $check( $batchId > 0 && true === ( $rd['batch']['undoable'] ?? true ), 'the run recorded one undoable batch', $rd['batch'] ?? null );
            if ( $batchId > 0 ) {
                update_post_meta( $S, '_aied_switch_batch', $batchId );
            }
            $hist = $history( $S );
            $check( count( $hist ) === $hist0 + 1 && in_array( 'switch_design', array_column( $hist, 'tool' ), true ), 'the previous content was snapshotted in the history by switch_design (one new entry)', array_slice( $hist, 0, 2 ) );
            $again = $rest( $route, $base + [ 'token' => $token ] );
            $check( 400 === $again['status'], 'the same token a second time is refused (single use)', $again['status'] );
            $check( $stored( $S ) === $direct && count( $history( $S ) ) === $hist0 + 1, 'the refused second run changed nothing' );

            // ---- 4. MCP parity ----
            $list = $mcpRpc( 'tools/list', [] );
            $names = array_map( static fn ( $t ): string => (string) ( $t['name'] ?? '' ), (array) ( $list['result']['tools'] ?? [] ) );
            $check( in_array( SwitchDesign::NAME, $names, true ), 'MCP tools/list names switch_design' );
            $mArgs = [ 'page_id' => $M, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1] ];
            $mp    = $mcpTool( SwitchDesign::NAME, $mArgs + [ 'dry_run' => true ] );
            $mrow  = (array) ( $mp['result']['pages'][0] ?? [] );
            $check( ! $mp['isError'] && null === $mp['error'] && 1 === ( $mp['result']['would_change'] ?? null ) && true === ( $mrow['valid'] ?? null ), 'MCP dry run: not an error, one page would change, valid:true', $mp['error'] ?? $mp['result'] );
            $check( $norm( $mrow['report'] ?? null ) === $expected && $norm( $mrow['from'] ?? null ) === $norm( $row['from'] ?? 1 ) && $norm( $mrow['to'] ?? null ) === $norm( $row['to'] ?? 1 ), 'the MCP preview reports the same design and the same counts as REST' );
            $mtoken = (string) ( $mp['result']['token'] ?? '' );
            $mr     = $mcpTool( SwitchDesign::NAME, $mArgs + [ 'token' => $mtoken ] );
            $check( ! $mr['isError'] && 1 === ( $mr['result']['switched'] ?? null ) && true === ( $mr['result']['changed'] ?? null ), 'MCP run with the token: switched 1', $mr['error'] ?? $mr['result'] );
            $check( $stored( $M ) === $direct, 'the page switched through MCP EQUALS the directly built page, byte for byte' );
            $mbatch = (int) ( $mr['result']['batch']['id'] ?? 0 );
            if ( $mbatch > 0 ) {
                update_post_meta( $M, '_aied_switch_batch', $mbatch );
            }
            $mAgain = $mcpTool( SwitchDesign::NAME, $mArgs + [ 'token' => $mtoken ] );
            $check( null !== $mAgain['error'] && -32602 === ( $mAgain['error']['code'] ?? null ), 'MCP: the same token again is refused (-32602)', $mAgain['error'] ?? null );
            $mBad = $mcpTool( SwitchDesign::NAME, [ 'page_id' => 'abc', 'to_language' => 'luxe', 'dry_run' => true ] );
            $check( null !== $mBad['error'] && -32602 === ( $mBad['error']['code'] ?? null ), 'MCP: a page_id that is not a number is refused (-32602)', $mBad['error'] ?? null );

            // ---- 5. permissions ----
            $deleteUser();
            $uid = wp_insert_user( [ 'user_login' => AIED_SW_USER . $run, 'user_pass' => wp_generate_password( 24 ), 'user_email' => AIED_SW_USER . $run . '@example.invalid', 'role' => 'author' ] );
            $check( ! is_wp_error( $uid ), 'a throwaway author user was created', is_wp_error( $uid ) ? $uid->get_error_message() : null );
            if ( ! is_wp_error( $uid ) ) {
                $nBefore = $stored( $N );
                $deny    = static function ( array $caps, string $cap, int $user, array $a ) use ( $N ): array {
                    return 'edit_post' === $cap && (int) ( $a[0] ?? 0 ) === $N ? [ 'do_not_allow' ] : $caps;
                };
                try {
                    wp_set_current_user( (int) $uid );
                    $author = $rest( $route, [ 'page_id' => $N, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1], 'dry_run' => true ] );
                    $check( 403 === $author['status'], 'a user without edit_pages (an author) is refused (403)', $author['status'] );

                    wp_set_current_user( 1 );
                    wp_update_user( [ 'ID' => (int) $uid, 'role' => 'editor' ] );
                    $adminPreview = $rest( $route, [ 'page_id' => $N, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1], 'dry_run' => true ] );
                    $adminToken   = (string) ( $adminPreview['data']['token'] ?? '' );
                    wp_set_current_user( (int) $uid );
                    $editor = $rest( $route, [ 'page_id' => $N, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1], 'dry_run' => true ] );
                    $check( 200 === $editor['status'] && 1 === ( $editor['data']['would_change'] ?? null ), 'an editor may preview a page they can edit', $editor['status'] );

                    add_filter( 'map_meta_cap', $deny, 99, 4 );
                    $denied = $rest( $route, [ 'page_id' => $N, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1], 'dry_run' => true ] );
                    $check( 200 === $denied['status'] && 0 === ( $denied['data']['would_change'] ?? null ) && null === ( $denied['data']['token'] ?? 0 ) && [ 'id' => $N, 'reason' => 'forbidden' ] === ( $denied['data']['pages'][0] ?? null ), 'an editor without edit_post on the page: one bare {id, reason: forbidden} row (no title), would_change 0, no token', $denied['data']['pages'][0] ?? $denied['data'] );
                    $stolen = '' === $adminToken ? ['status' => 0] : $rest( $route, [ 'page_id' => $N, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1], 'token' => $adminToken ] );
                    $check( '' !== $adminToken && 400 === $stolen['status'], 'another user\'s token is refused (400)', $stolen['status'] );
                    remove_filter( 'map_meta_cap', $deny, 99 );
                    $check( $stored( $N ) === $nBefore, 'the denied page was not changed by any of this' );
                } finally {
                    remove_filter( 'map_meta_cap', $deny, 99 );
                    wp_set_current_user( 1 );
                    $deleteUser();
                }
            }
            $info( 'batches', [ 'rest' => $batchId, 'mcp' => $mbatch ] );
        }
    }

    // =========================================================================================================================
    if ( 'changed' === $stage ) {
        $X = $find( 'changed' );
        $check( $X > 0, 'the page for the changed-since-preview check exists (run build first)' );
        if ( $X > 0 ) {
            $args2 = [ 'page_id' => $X, 'to_language' => $targetDesign[0], 'to_kit' => $targetDesign[1] ];
            $p     = $rest( '/pro/switch_design', $args2 + [ 'dry_run' => true ] );
            $check( 200 === $p['status'] && 1 === ( $p['data']['would_change'] ?? null ) && '' !== (string) ( $p['data']['token'] ?? '' ), 'the preview of the page to be changed offers a token' );
            // Someone edits the page between the preview and the run (a valid page, still in the source design).
            $edited = $build( $sourceDesign[0], $sourceDesign[1], [ 'faq-accordion' ] );
            $check( null !== $edited, 'the replacement content resolves' );
            if ( null !== $edited ) {
                wp_update_post( wp_slash( [ 'ID' => $X, 'post_content' => $edited ] ) );
                $check( $stored( $X ) === $edited, 'the page was edited after the preview' );
                $r = $rest( '/pro/switch_design', $args2 + [ 'token' => (string) $p['data']['token'] ] );
                $check( 200 === $r['status'] && false === ( $r['data']['changed'] ?? null ) && 0 === ( $r['data']['switched'] ?? -1 ), 'the run switched nothing', $r['data'] );
                $check( 'changed_since_preview' === ( $r['data']['skipped'][0]['reason'] ?? null ), 'the page is skipped with reason changed_since_preview', $r['data']['skipped'] ?? null );
                $check( $stored( $X ) === $edited, 'the edited page was NOT overwritten (byte for byte)' );
            }
        }
    }

    // =========================================================================================================================
    if ( 'undo' === $stage ) {
        $S = $find( 'source' );
        $check( $S > 0, 'the switched page exists (run build and switch first)' );
        if ( $S > 0 ) {
            $original = (string) get_post_meta( $S, '_aied_switch_original', true );
            $batchId  = (int) get_post_meta( $S, '_aied_switch_batch', true );
            $check( '' !== $original && $batchId > 0, 'the original content and the batch id were recorded by the earlier stages' );
            $check( $stored( $S ) !== $original, 'before the undo the page is the switched one' );
            if ( '' !== $original && $batchId > 0 ) {
                $u = $rest( '/pro/undo_batch', [ 'batch_id' => $batchId ] );
                $check( 200 === $u['status'] && true === ( $u['data']['fully_undone'] ?? null ) && 1 === ( $u['data']['restored'] ?? null ), 'undo_batch: fully undone, one item restored', $u['status'] . ' ' . wp_json_encode( array_intersect_key( $u['data'], [ 'fully_undone' => 1, 'restored' => 1, 'message' => 1 ] ) ) );
                $check( $stored( $S ) === $original, 'the undo restored the original page byte for byte', $sha( $stored( $S ) ) . ' vs ' . $sha( $original ) );
            }
        }
    }

    // =========================================================================================================================
    if ( 'cleanup' === $stage ) {
        $prefix = 'all' === $run ? AIED_SW_TITLE : AIED_SW_TITLE . $run . ' ';
        $ids    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ) ); // phpcs:ignore
        $batches = [];
        foreach ( $ids as $id ) {
            $b = (int) get_post_meta( $id, '_aied_switch_batch', true );
            if ( $b > 0 ) {
                $batches[] = $b;
            }
        }
        foreach ( $ids as $id ) {
            wp_delete_post( $id, true );
        }
        if ( [] !== $batches && BatchTable::exists() ) {
            $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batches ) ) ) . ')' ); // phpcs:ignore
        }
        $logins = 'all' === $run
            ? (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( AIED_SW_USER ) . '%' ) ) // phpcs:ignore
            : [ AIED_SW_USER . $run ];
        foreach ( $logins as $login ) {
            $u = get_user_by( 'login', (string) $login );
            if ( $u instanceof WP_User && str_starts_with( $u->user_login, AIED_SW_USER ) && (int) $u->ID > 1 ) {
                wp_delete_user( (int) $u->ID );
            }
        }
        $left      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore
        $leftUsers = 0;
        foreach ( $logins as $login ) {
            $leftUsers += get_user_by( 'login', (string) $login ) instanceof WP_User ? 1 : 0;
        }
        $leftBatches = [] === $batches || ! BatchTable::exists() ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batches ) ) ) . ')' ); // phpcs:ignore
        $check( 0 === $left, 'no `aied switch ` post remains (deleted ' . count( $ids ) . ')', $left );
        $check( 0 === $leftUsers, 'no throwaway user remains' );
        $check( 0 === $leftBatches, 'no batch row of this gate remains (' . count( $batches ) . ' removed)' );
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
