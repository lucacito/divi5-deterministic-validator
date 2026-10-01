<?php
/**
 * Real-site check for editing the live Theme Builder header and footer through the page tools. The unit
 * tests cover the rule (EditTarget, ThemeLayouts) and the wiring; this drives the REAL REST and MCP
 * handlers against Divi's own Theme Builder posts on the Docker site and proves: listing never creates
 * anything, a live header/footer reads, previews, edits, snapshots and restores like a page, every other
 * Theme Builder post (orphan, draft-only, body, template, the Theme Builder post itself) and ordinary
 * posts stay "not found", the three permission layers each refuse (edit_post, edit_others_posts and
 * Divi's Role Editor 'theme_builder'), and the template assignment is never touched.
 *
 * Run: make verify-theme-layouts   (needs `make up` and the plugin active on the dev site)
 *
 * It builds throwaway Theme Builder fixtures with Divi's own functions (the throwaway template has
 * default 0 and an empty use_on, so it applies to nothing on the site) and removes exactly what it
 * created, even when a check fails or throws. The Divi Role Editor option is changed temporarily as
 * DEV-SITE test setup (the plugin itself never writes it) and put back exactly.
 * The front end is not fetched: the real front-end proof was obtained in an earlier spike; this
 * script proves the stored-content and data path.
 */

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use Divi5Validator\Validator;

if ( ! class_exists( HistoryStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}
foreach ( [ 'et_theme_builder_get_theme_builder_post_id', 'et_theme_builder_insert_layout', 'et_theme_builder_store_template', 'et_theme_builder_clear_wp_cache', 'et_pb_is_allowed' ] as $fn ) {
    if ( ! function_exists( $fn ) ) {
        WP_CLI::error( 'Divi Theme Builder functions not available' );
    }
}

global $wpdb;

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$layout = static function ( string $title ): string {
    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $title . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$rows = static function ( int $pageId ): int {
    return count( HistoryStore::summaries( $pageId ) );
};
$state = static function ( int $pageId ): array {
    clean_post_cache( $pageId );
    $p = get_post( $pageId );

    return [ 'content' => $p->post_content, 'modified' => $p->post_modified_gmt, 'history' => count( HistoryStore::summaries( $pageId ) ) ];
};
$rest = static function ( string $method, string $route, array $body = [] ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] ) );
    // The MCP endpoint accepts only the plugin API key (Bearer), never a plain logged-in user.
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 ); // authenticateRequest() switched to the key owner; back to --user=1 for the REST calls
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'isError' => true, 'result' => null ];
    }
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => json_decode( (string) $text, true ) ];
};

$tbTypes = [ 'et_theme_builder', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];
$countTb = static function ( ?string $only = null ) use ( $wpdb, $tbTypes ): int {
    $types = null === $only ? $tbTypes : [ $only ];
    $in    = "'" . implode( "','", $types ) . "'";

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$in})" ); // phpcs:ignore
};
$countAied = static function () use ( $wpdb ): int {
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify%'" ); // phpcs:ignore
};
$roleSentinel = '__aied_verify_absent__';
$roleOption   = 'et_pb_role_settings';
$backupOption = static fn ( int $tb ): string => 'et_tb_templates_backup_' . $tb;

HistoryTable::ensureReady( true );

// ---- 1. guard + baseline ---------------------------------------------------------------------------
$baselineCount = $countTb();
$liveTbBefore  = (int) et_theme_builder_get_theme_builder_post_id( true, false );
$draftTbBefore = (int) et_theme_builder_get_theme_builder_post_id( false, false );
$roleBefore    = get_option( $roleOption, $roleSentinel );
$roleExisted   = $roleSentinel !== $roleBefore;
WP_CLI::log( "baseline: {$baselineCount} Theme Builder post(s); live Theme Builder id {$liveTbBefore}; draft Theme Builder id {$draftTbBefore}; {$roleOption} " . ( $roleExisted ? 'exists' : 'does not exist' ) );
if ( $liveTbBefore > 0 ) {
    $tbTemplatesBefore = get_post_meta( $liveTbBefore, '_et_template', false );
}

$H = $F = $B = $O = $T = $DL = $DT = $P = $PG = 0;
$tb = $draftTb = 0;
$createdTb = $createdDraftTb = false;
$liveBackupBefore = $draftBackupBefore = null;
$draftTemplatesBefore = [];
$created = []; // every post id this run creates

$insertLayout = static function ( string $type, string $title, string $content ) use ( &$created ): int {
    $id = et_theme_builder_insert_layout( [ 'post_type' => $type, 'post_title' => $title, 'post_content' => wp_slash( $content ) ] );
    if ( is_wp_error( $id ) || ! $id ) {
        throw new \RuntimeException( "could not create the {$type} fixture" );
    }
    $created[] = (int) $id;
    update_post_meta( $id, '_et_pb_use_divi_5', 'on' );
    update_post_meta( $id, '_et_pb_use_builder', 'on' );

    return (int) $id;
};
$insertTemplate = static function ( int $themeBuilderId, string $title, int $header, int $body, int $footer ) use ( &$created ): int {
    $id = et_theme_builder_store_template(
        $themeBuilderId,
        [
            'title'               => $title,
            'default'             => '0',
            'enabled'             => '1',
            'autogenerated_title' => '0',
            'layouts'             => [
                'header' => [ 'id' => $header, 'enabled' => true ],
                'body'   => [ 'id' => $body, 'enabled' => true ],
                'footer' => [ 'id' => $footer, 'enabled' => true ],
            ],
            'use_on'              => [],
            'exclude_from'        => [],
        ],
        true
    );
    if ( ! $id ) {
        throw new \RuntimeException( "could not create the template fixture {$title}" );
    }
    $created[] = (int) $id;

    return (int) $id;
};

try {
    $check( ( new Validator() )->validateContent( $layout( 'x' ) )->isValid(), 'the fixture layout used by this script passes the checker' );
    $check( et_pb_is_allowed( 'theme_builder' ) && current_user_can( 'edit_others_posts' ), 'setup: user 1 starts with edit_others_posts and the Divi theme_builder capability' );

    // ---- 2. listing must not create anything ---------------------------------------------------------
    $tbCountBefore = $countTb( 'et_theme_builder' );
    $r = $rest( 'GET', '/theme-layouts' );
    $m = $mcp( 'list_theme_layouts', [] );
    $check( 200 === $r['status'] && ! $m['isError'], 'listing before any fixture: REST 200 and MCP not an error' );
    $check( $countTb( 'et_theme_builder' ) === $tbCountBefore && (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbBefore, 'listing never creates a Theme Builder post' );
    if ( 0 === $liveTbBefore ) {
        $check( 0 === ( $r['data']['count'] ?? null ) && 0 === ( $m['result']['count'] ?? null ) && is_string( $r['data']['note'] ?? null ), 'with no live Theme Builder the listing is empty and carries the explanatory note (REST and MCP)' );
    }

    // ---- 3. fixtures, built with Divi's own functions -------------------------------------------------
    $tb        = (int) et_theme_builder_get_theme_builder_post_id( true, true );
    $createdTb = 0 === $liveTbBefore;
    if ( $createdTb ) {
        $created[] = $tb;
    } else {
        $liveBackupBefore = get_option( $backupOption( $tb ), null );
    }

    $H  = $insertLayout( 'et_header_layout', 'aied verify header', $layout( 'AIED-HEADER-V1' ) );
    $F  = $insertLayout( 'et_footer_layout', 'aied verify footer', $layout( 'AIED-FOOTER-V1' ) );
    $B  = $insertLayout( 'et_body_layout', 'aied verify body', $layout( 'AIED-BODY-V1' ) );
    $O  = $insertLayout( 'et_header_layout', 'aied verify orphan header', $layout( 'AIED-ORPHAN-V1' ) );
    $T  = $insertTemplate( $tb, 'aied verify template', $H, $B, $F );
    add_post_meta( $tb, '_et_template', $T ); // append, never replace
    if ( is_array( $liveBackupBefore ) ) {
        update_option( $backupOption( $tb ), array_merge( array_map( 'intval', $liveBackupBefore ), [ $T ] ) );
    }

    // draft-only: a layout used only by a template of the DRAFT Theme Builder
    $draftTb        = (int) et_theme_builder_get_theme_builder_post_id( false, true );
    $createdDraftTb = 0 === $draftTbBefore;
    if ( $createdDraftTb ) {
        $created[] = $draftTb;
    } else {
        $draftBackupBefore    = get_option( $backupOption( $draftTb ), null );
        $draftTemplatesBefore = get_post_meta( $draftTb, '_et_template', false );
    }
    $DL = $insertLayout( 'et_header_layout', 'aied verify draft header', $layout( 'AIED-DRAFT-V1' ) );
    $DT = $insertTemplate( $draftTb, 'aied verify draft template', $DL, 0, 0 );
    add_post_meta( $draftTb, '_et_template', $DT );

    $P = (int) wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'aied verify post', 'post_content' => wp_slash( $layout( 'AIED-POST-V1' ) ) ] );
    $created[] = $P;
    et_theme_builder_clear_wp_cache( 'all' );

    $check( $H > 0 && $F > 0 && $B > 0 && $O > 0 && $T > 0 && $DL > 0 && $DT > 0 && $P > 0 && $tb > 0 && $draftTb > 0, 'fixtures created: header, footer, body, orphan header, live template, draft layout and template, an ordinary post' );
    clean_post_cache( $H );
    $check( get_post( $H )->post_content === $layout( 'AIED-HEADER-V1' ) && get_post( $F )->post_content === $layout( 'AIED-FOOTER-V1' ), 'the fixture layouts stored byte-exact content (wp_slash)' );

    // ---- 4. assignment state, snapshotted ----------------------------------------------------------------
    $metaSnapshot   = static fn ( int $id ): array => get_post_meta( $id );
    $metaT          = $metaSnapshot( $T );
    $metaDT         = $metaSnapshot( $DT );
    $tbSnapshot     = get_post_meta( $tb, '_et_template', false );
    $draftTbSnap    = get_post_meta( $draftTb, '_et_template', false );
    $liveTbAfterFix = (int) et_theme_builder_get_theme_builder_post_id( true, false );
    $draftTbAfterFix = (int) et_theme_builder_get_theme_builder_post_id( false, false );
    $check( $liveTbAfterFix === $tb && $draftTbAfterFix === $draftTb, 'the Divi live and draft Theme Builder posts resolve to the ones this run uses' );

    // ---- 5a. listing -----------------------------------------------------------------------------------
    $r = $rest( 'GET', '/theme-layouts' );
    $m = $mcp( 'list_theme_layouts', [] );
    $find = static function ( array $payload ) use ( $T ): ?array {
        foreach ( (array) ( $payload['templates'] ?? [] ) as $t ) {
            if ( (int) ( $t['template_id'] ?? 0 ) === $T ) {
                return (array) $t;
            }
        }

        return null;
    };
    foreach ( [ 'REST GET /theme-layouts' => $r['data'], 'MCP list_theme_layouts' => (array) $m['result'] ] as $label => $payload ) {
        $t = $find( $payload );
        $check( null !== $t, "{$label} lists the live template" );
        $check( null !== $t && $H === ( $t['header']['layout_id'] ?? null ) && $F === ( $t['footer']['layout_id'] ?? null ) && $B === ( $t['body']['layout_id'] ?? null ), "{$label}: header, footer and body layout ids are the fixtures'" );
        $check( null !== $t && true === ( $t['header']['editable'] ?? null ) && true === ( $t['footer']['editable'] ?? null ) && false === ( $t['body']['editable'] ?? null ), "{$label}: header and footer are editable, the body layout is listed but not editable" );
        $check( null !== $t && false === $t['default'] && [] === $t['use_on'], "{$label}: the throwaway template is not the default and applies to nothing" );
        $json = (string) wp_json_encode( $payload );
        $ids  = [];
        foreach ( (array) ( $payload['templates'] ?? [] ) as $tt ) {
            foreach ( [ 'header', 'footer', 'body' ] as $k ) {
                if ( isset( $tt[ $k ]['layout_id'] ) ) {
                    $ids[] = (int) $tt[ $k ]['layout_id'];
                }
            }
        }
        $check( ! in_array( $O, $ids, true ) && ! in_array( $DL, $ids, true ) && false === strpos( $json, 'aied verify orphan' ) && false === strpos( $json, 'aied verify draft' ), "{$label}: the orphan header and the draft-only layout appear nowhere" );
    }
    $check( wp_json_encode( $r['data'] ) === wp_json_encode( $m['result'] ), 'REST and MCP listings are identical' );

    // ---- 5b. read ----------------------------------------------------------------------------------------
    $r = $rest( 'GET', "/pages/{$H}" );
    $check( 200 === $r['status'] && 'et_header_layout' === ( $r['data']['post_type'] ?? null ) && $layout( 'AIED-HEADER-V1' ) === ( $r['data']['post_content'] ?? null ), 'REST GET /pages/{header}: 200, post_type et_header_layout, the layout content' );
    $m = $mcp( 'get_page_layout', [ 'page_id' => $H ] );
    $check( ! $m['isError'] && 'et_header_layout' === ( $m['result']['post_type'] ?? null ) && $layout( 'AIED-HEADER-V1' ) === ( $m['result']['post_content'] ?? null ), 'MCP get_page_layout(header): the layout content, post_type et_header_layout' );
    $r = $rest( 'GET', "/pages/{$F}" );
    $check( 200 === $r['status'] && 'et_footer_layout' === ( $r['data']['post_type'] ?? null ), 'REST GET /pages/{footer}: 200, post_type et_footer_layout' );

    // ---- 5c. dry run -------------------------------------------------------------------------------------
    $before = $state( $H );
    sleep( 1 ); // post_modified has one-second resolution: a real write would be visible
    $r = $rest( 'POST', "/pages/{$H}/edit", [ 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-V2', 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === ( $r['data']['saved'] ?? null ) && 1 === ( $r['data']['replaced'] ?? null ), 'REST edit dry_run on the header: 200, saved false, replaced 1' );
    $after = $state( $H );
    $check( $after['content'] === $before['content'] && $after['modified'] === $before['modified'] && $after['history'] === $before['history'] && 0 === $after['history'], 'the header dry run left post_content, post_modified and history untouched' );

    // ---- 5d. real REST edit on the header --------------------------------------------------------------------
    $r = $rest( 'POST', "/pages/{$H}/edit", [ 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-V2' ] );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ) && true === ( $r['data']['history']['stored'] ?? null ), 'real REST edit of the header: saved true, history.stored true (the cache-clear path did not fatal)' );
    clean_post_cache( $H );
    $check( false !== strpos( get_post( $H )->post_content, 'AIED-HEADER-V2' ) && false === strpos( get_post( $H )->post_content, 'AIED-HEADER-V1' ), 'the stored header content now contains AIED-HEADER-V2' );
    $check( 1 === $rows( $H ), 'exactly one history row exists for the header' );
    $r = $rest( 'GET', "/pages/{$H}/history" );
    $check( 200 === $r['status'] && 1 === ( $r['data']['count'] ?? null ), 'REST list history of the header shows 1 version' );
    $versionH = (int) ( $r['data']['versions'][0]['id'] ?? 0 );
    $r = $rest( 'POST', "/pages/{$H}/restore", [ 'version_id' => $versionH ] );
    clean_post_cache( $H );
    $check( 200 === $r['status'] && true === ( $r['data']['restored'] ?? null ) && get_post( $H )->post_content === $layout( 'AIED-HEADER-V1' ), 'REST restore puts the header back byte for byte' );

    // ---- 5e. real MCP edit on the footer, history, restore -----------------------------------------------------
    $m = $mcp( 'edit_page_content', [ 'page_id' => $F, 'find' => 'AIED-FOOTER-V1', 'replace' => 'AIED-FOOTER-V2' ] );
    $check( ! $m['isError'] && true === ( $m['result']['saved'] ?? null ) && true === ( $m['result']['history']['stored'] ?? null ), 'real MCP edit_page_content on the footer: saved true, history.stored true' );
    clean_post_cache( $F );
    $check( false !== strpos( get_post( $F )->post_content, 'AIED-FOOTER-V2' ), 'the stored footer content now contains AIED-FOOTER-V2' );
    $m = $mcp( 'list_page_history', [ 'page_id' => $F ] );
    $check( ! $m['isError'] && 1 === ( $m['result']['count'] ?? null ), 'MCP list_page_history of the footer shows 1 version' );
    $r = $rest( 'GET', "/pages/{$F}/history" );
    $check( 200 === $r['status'] && 1 === ( $r['data']['count'] ?? null ) && ( $m['result']['versions'][0]['id'] ?? 0 ) === ( $r['data']['versions'][0]['id'] ?? -1 ), 'REST and MCP history of the footer show the same version' );
    $versionF = (int) ( $m['result']['versions'][0]['id'] ?? 0 );
    $m = $mcp( 'restore_page_version', [ 'page_id' => $F, 'version_id' => $versionF ] );
    clean_post_cache( $F );
    $check( ! $m['isError'] && true === ( $m['result']['restored'] ?? null ) && get_post( $F )->post_content === $layout( 'AIED-FOOTER-V1' ), 'MCP restore_page_version puts the footer back byte for byte' );
    $check( 2 === $rows( $F ), 'the restore snapshotted the current footer first (2 history rows, so the restore is undoable)' );

    // ---- 5f. everything else is "not found" ----------------------------------------------------------------------
    $refused = [ 'orphan header' => $O, 'draft-only layout' => $DL, 'body layout' => $B, 'template' => $T, 'live Theme Builder post' => $tb, 'ordinary post' => $P ];
    foreach ( $refused as $name => $rid ) {
        $b = $state( $rid );
        $calls = [
            'REST get'     => $rest( 'GET', "/pages/{$rid}" ),
            'REST edit'    => $rest( 'POST', "/pages/{$rid}/edit", [ 'find' => 'AIED', 'replace' => 'X' ] ),
            'REST update'  => $rest( 'PUT', "/pages/{$rid}", [ 'post_content' => $layout( 'Hijack' ) ] ),
            'REST history' => $rest( 'GET', "/pages/{$rid}/history" ),
            'REST restore' => $rest( 'POST', "/pages/{$rid}/restore", [ 'version_id' => 1 ] ),
        ];
        foreach ( $calls as $label => $res ) {
            $check( 404 === $res['status'] && 'not_found' === ( $res['data']['code'] ?? null ), "{$label} on the {$name} is 404 not_found" );
        }
        $mcalls = [
            'MCP get_page_layout'     => $mcp( 'get_page_layout', [ 'page_id' => $rid ] ),
            'MCP edit_page_content'   => $mcp( 'edit_page_content', [ 'page_id' => $rid, 'find' => 'AIED', 'replace' => 'X' ] ),
            'MCP update_page_layout'  => $mcp( 'update_page_layout', [ 'page_id' => $rid, 'post_content' => $layout( 'Hijack' ) ] ),
            'MCP list_page_history'   => $mcp( 'list_page_history', [ 'page_id' => $rid ] ),
            'MCP restore_page_version' => $mcp( 'restore_page_version', [ 'page_id' => $rid, 'version_id' => 1 ] ),
        ];
        foreach ( $mcalls as $label => $res ) {
            $check( true === $res['isError'] && -32602 === ( $res['error']['code'] ?? null ) && "Page {$rid} not found." === ( $res['error']['message'] ?? null ), "{$label} on the {$name} is -32602 'Page {$rid} not found.'" );
        }
        $a = $state( $rid );
        $check( $a === $b && 0 === $a['history'], "the {$name} is unchanged: same content, same post_modified, no history rows" );
    }

    // ---- 5g. layer 2: no edit_others_posts ------------------------------------------------------------------------
    $PG = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied verify page', 'post_content' => wp_slash( $layout( 'AIED-PAGE-V1' ) ) ] );
    $created[] = $PG;
    $headerBefore = $state( $H );
    $stripCap = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_others_posts' => false ] );
    add_filter( 'user_has_cap', $stripCap );
    try {
        $check( ! current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_post', $H ), 'setup: edit_others_posts is gone but edit_post on the layout still holds (the layouts are authored by user 1)' );
        $r = $rest( 'GET', "/pages/{$H}" );
        $check( 403 === $r['status'] && 'forbidden' === ( $r['data']['code'] ?? null ), 'without edit_others_posts: REST GET /pages/{header} is 403' );
        $m = $mcp( 'edit_page_content', [ 'page_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-NOPE' ] );
        $check( true === $m['isError'] && false !== stripos( (string) ( $m['error']['message'] ?? '' ), 'permission' ), 'without edit_others_posts: MCP edit_page_content on the header is refused with a permission error' );
        $r = $rest( 'POST', "/pages/{$H}/edit", [ 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-NOPE' ] );
        $check( 403 === $r['status'], 'without edit_others_posts: REST edit of the header is 403' );
        $r = $rest( 'GET', '/theme-layouts' );
        $check( 403 === $r['status'], 'without edit_others_posts: REST GET /theme-layouts is 403' );
        $m = $mcp( 'list_theme_layouts', [] );
        $check( true === $m['isError'] && false !== stripos( (string) ( $m['error']['message'] ?? '' ), 'permission' ), 'without edit_others_posts: MCP list_theme_layouts is refused' );
        $r = $rest( 'GET', "/pages/{$PG}" );
        $check( 200 === $r['status'] && 'page' === ( $r['data']['post_type'] ?? null ), 'without edit_others_posts: a normal page still reads (200)' );
        $m = $mcp( 'get_page_layout', [ 'page_id' => $PG ] );
        $check( ! $m['isError'], 'without edit_others_posts: MCP get_page_layout on a normal page still works' );
    } finally {
        remove_filter( 'user_has_cap', $stripCap );
    }
    $check( current_user_can( 'edit_others_posts' ), 'the edit_others_posts filter was removed again' );

    // ---- 5h. layer 3: Divi's Role Editor ----------------------------------------------------------------------------
    $roleTestRan = false;
    try {
        $settings                                   = is_array( $roleExisted ? $roleBefore : [] ) ? ( $roleExisted ? $roleBefore : [] ) : [];
        $settings['administrator']                  = isset( $settings['administrator'] ) && is_array( $settings['administrator'] ) ? $settings['administrator'] : [];
        $settings['administrator']['theme_builder'] = 'off';
        update_option( $roleOption, $settings );
        unset( $GLOBALS['et_pb_role_settings'] ); // Divi caches the option in this global
        wp_cache_flush();
        $roleTestRan = true;
        $check( ! et_pb_is_allowed( 'theme_builder' ) && current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_post', $H ), 'setup: Divi now denies theme_builder while edit_others_posts and edit_post still hold' );
        $r = $rest( 'GET', "/pages/{$H}" );
        $check( 403 === $r['status'] && 'forbidden' === ( $r['data']['code'] ?? null ), 'Role Editor theme_builder off: REST GET /pages/{header} is 403' );
        $m = $mcp( 'edit_page_content', [ 'page_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-NOPE' ] );
        $check( true === $m['isError'] && false !== stripos( (string) ( $m['error']['message'] ?? '' ), 'permission' ), 'Role Editor theme_builder off: MCP edit_page_content on the header is refused with a permission error' );
        $r = $rest( 'GET', '/theme-layouts' );
        $check( 403 === $r['status'], 'Role Editor theme_builder off: REST GET /theme-layouts is 403' );
        $r = $rest( 'GET', "/pages/{$PG}" );
        $check( 200 === $r['status'], 'Role Editor theme_builder off: a normal page still reads (200)' );
    } finally {
        if ( $roleExisted ) {
            update_option( $roleOption, $roleBefore );
        } else {
            delete_option( $roleOption );
        }
        unset( $GLOBALS['et_pb_role_settings'] );
        wp_cache_flush();
        $now = get_option( $roleOption, $roleSentinel );
        $check( ( $roleExisted ? $now === $roleBefore : $roleSentinel === $now ) && et_pb_is_allowed( 'theme_builder' ), "the Divi Role Editor option {$roleOption} is back to " . ( $roleExisted ? 'its previous value' : 'not existing' ) . ' and Divi allows theme_builder again' );
    }
    $headerAfter = $state( $H );
    $check( $headerAfter['content'] === $headerBefore['content'] && $headerAfter['modified'] === $headerBefore['modified'] && $headerAfter['history'] === $headerBefore['history'], 'none of the refused permission calls changed the header (content, post_modified, history)' );

    // ---- 5i. the template assignment is untouched ----------------------------------------------------------------------
    clean_post_cache( $T );
    $check( $metaSnapshot( $T ) === $metaT, 'the live template meta is identical (no _et_use_on, _et_default, _et_enabled, *_layout_id or *_layout_enabled change)' );
    $check( $metaSnapshot( $DT ) === $metaDT, 'the draft template meta is identical' );
    $check( get_post_meta( $tb, '_et_template', false ) === $tbSnapshot && get_post_meta( $draftTb, '_et_template', false ) === $draftTbSnap, 'the Theme Builder posts still link exactly the same templates' );
    $check( (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbAfterFix && (int) et_theme_builder_get_theme_builder_post_id( false, false ) === $draftTbAfterFix, 'the live and draft Theme Builder post ids are the same as after fixture creation' );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    // ---- 6. cleanup: exactly what this run created, newest first -----------------------------------------------------
    try {
        // The Role Editor option is restored in its own finally above; this is only a last-resort guard
        // for an exception thrown before that block was reached.
        if ( $roleSentinel === get_option( $roleOption, $roleSentinel ) && $roleExisted ) {
            update_option( $roleOption, $roleBefore );
        }
        $remove = static function ( int $id ): void {
            if ( $id > 0 && null !== get_post( $id ) ) {
                wp_delete_post( $id, true );
            }
        };
        foreach ( [ $P, $PG, $DT, $DL, $T, $B, $O, $F, $H ] as $id ) {
            $remove( (int) $id );
        }
        if ( $draftTb > 0 ) {
            if ( $createdDraftTb ) {
                $remove( $draftTb );
            } else {
                delete_post_meta( $draftTb, '_et_template', $DT );
                if ( get_post_meta( $draftTb, '_et_template', false ) !== $draftTemplatesBefore ) {
                    delete_post_meta( $draftTb, '_et_template' );
                    foreach ( $draftTemplatesBefore as $tid ) {
                        add_post_meta( $draftTb, '_et_template', $tid );
                    }
                }
            }
            null === $draftBackupBefore ? delete_option( $backupOption( $draftTb ) ) : update_option( $backupOption( $draftTb ), $draftBackupBefore );
        }
        if ( $tb > 0 ) {
            if ( $createdTb ) {
                $remove( $tb );
            } else {
                delete_post_meta( $tb, '_et_template', $T );
                if ( get_post_meta( $tb, '_et_template', false ) !== ( $tbTemplatesBefore ?? [] ) ) {
                    delete_post_meta( $tb, '_et_template' );
                    foreach ( $tbTemplatesBefore ?? [] as $tid ) {
                        add_post_meta( $tb, '_et_template', $tid );
                    }
                }
            }
            null === $liveBackupBefore ? delete_option( $backupOption( $tb ) ) : update_option( $backupOption( $tb ), $liveBackupBefore );
        }
        foreach ( $created as $id ) {
            $remove( (int) $id ); // anything created but not reached above (an abort part-way through the fixtures)
        }
        et_theme_builder_clear_wp_cache( 'all' );
        if ( class_exists( 'ET_Core_PageResource' ) ) {
            ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        }
    } catch ( \Throwable $e ) {
        $failures[] = 'cleanup aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
        WP_CLI::log( 'FAIL  cleanup aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
    }
}

// ---- the site is exactly as it was ----------------------------------------------------------------------------------------
$finalCount = $countTb();
$leftover   = $countAied();
$roleNow    = get_option( $roleOption, $roleSentinel );
$historyGone = true;
foreach ( [ $H, $F ] as $id ) {
    if ( $id > 0 && 0 !== count( HistoryStore::summaries( (int) $id ) ) ) {
        $historyGone = false;
    }
}
$check( $finalCount === $baselineCount && 0 === $leftover, "cleanup: Theme Builder post count {$finalCount} equals the baseline {$baselineCount} and no post titled 'aied verify ...' remains ({$leftover})" );
$check( $historyGone, 'cleanup: no history rows remain for the throwaway layouts' );
$check( $roleExisted ? $roleNow === $roleBefore : $roleSentinel === $roleNow, "cleanup: {$roleOption} is " . ( $roleExisted ? 'unchanged' : 'not existing, as at the start' ) );
$check( (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbBefore && (int) et_theme_builder_get_theme_builder_post_id( false, false ) === $draftTbBefore, 'cleanup: the live and draft Theme Builder post ids are what they were at the start' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Theme layouts verified on the real REST and MCP handlers.' );
