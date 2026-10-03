<?php
/**
 * Real-site check of the Pro Theme Builder layout tools (list_theme_layouts, update_theme_layout, edit_theme_layout),
 * driven through the REAL ToolRegistry (so the WordPress capability gate is part of what is proven), the real batch
 * table, the real free history table and Divi's own Theme Builder posts on the Docker site. It proves: listing never
 * creates anything; a live header/footer reads, previews (dry_run touches nothing), updates, edits, snapshots and
 * undoes byte for byte; every other Theme Builder post (orphan, draft-only, body, template, the Theme Builder post)
 * and ordinary posts and pages are refused; an invalid layout and custom CSS are refused with nothing changed; undo
 * never overwrites a layout changed since; the three permission layers (edit_theme_options, edit_others_posts and
 * Divi's Role Editor 'theme_builder') each refuse; and the template assignment is never touched.
 *
 * Run: make verify-pro-theme-layouts   (needs `make up`, the free plugin and the Pro add-on active on the dev site;
 * no licence is needed: the registry is built with the unlock forced)
 *
 * It builds throwaway Theme Builder fixtures with Divi's own functions (the throwaway template has default 0 and an
 * empty use_on, so it applies to nothing on the site) and removes exactly what it created, even when a check fails or
 * throws. The Divi Role Editor option is changed temporarily as DEV-SITE test setup and put back exactly.
 * WARNING: it records about 9 batches, and the batch store keeps only the newest 20, so on a site that already has
 * batches it evicts the oldest real ones. Run it on a throwaway dev site only.
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use Divi5Validator\Validator;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
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
$batchIds = [];
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
$call     = static function ( string $name, array $args ) use ( $registry, &$batchIds ): array {
    $res = $registry->claim( null, $name, $args, 1 );
    if ( ! $res instanceof WP_REST_Response ) {
        return [ 'error' => [ 'code' => 0, 'message' => 'not claimed' ], 'result' => null ];
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'result' => null ];
    }
    $result = (array) json_decode( (string) ( $data['result']['content'][0]['text'] ?? '' ), true );
    foreach ( [ 'batch', 'undo_batch' ] as $k ) {
        if ( isset( $result[ $k ]['id'] ) ) {
            $batchIds[] = (int) $result[ $k ]['id'];
        }
    }

    return [ 'error' => null, 'result' => $result ];
};
$errHas = static fn ( array $r, string $needle ): bool => null !== $r['error'] && false !== stripos( (string) $r['error']['message'], $needle );
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
BatchTable::ensureReady( true );

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
    $l = $call( 'list_theme_layouts', [] );
    $check( null === $l['error'], 'listing before any fixture is not an error' );
    $check( $countTb( 'et_theme_builder' ) === $tbCountBefore && (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbBefore, 'listing never creates a Theme Builder post' );
    if ( 0 === $liveTbBefore ) {
        $check( 0 === ( $l['result']['count'] ?? null ) && is_string( $l['result']['note'] ?? null ), 'with no live Theme Builder the listing is empty and carries the explanatory note' );
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
    $l = $call( 'list_theme_layouts', [] );
    $t = null;
    foreach ( (array) ( $l['result']['templates'] ?? [] ) as $row ) {
        if ( (int) ( $row['template_id'] ?? 0 ) === $T ) {
            $t = (array) $row;
        }
    }
    $check( null !== $t, 'list_theme_layouts lists the live template' );
    $check( null !== $t && $H === ( $t['header']['layout_id'] ?? null ) && $F === ( $t['footer']['layout_id'] ?? null ) && $B === ( $t['body']['layout_id'] ?? null ), 'header, footer and body layout ids are the fixtures\'' );
    $check( null !== $t && true === ( $t['header']['editable'] ?? null ) && true === ( $t['footer']['editable'] ?? null ) && false === ( $t['body']['editable'] ?? null ), 'header and footer are editable, the body layout is listed but not editable' );
    $check( null !== $t && false === $t['default'] && [] === $t['use_on'], 'the throwaway template is not the default and applies to nothing' );
    $json = (string) wp_json_encode( $l['result'] );
    $check( false === strpos( $json, 'aied verify orphan' ) && false === strpos( $json, 'aied verify draft' ), 'the orphan header and the draft-only layout appear nowhere in the listing' );
    $c = $call( 'list_theme_layouts', [ 'layout_id' => $H ] );
    $check( null === $c['error'] && 'header' === ( $c['result']['layout']['kind'] ?? null ) && $layout( 'AIED-HEADER-V1' ) === ( $c['result']['layout']['content'] ?? null ), 'list_theme_layouts(layout_id) returns the header\'s content byte for byte' );

    // ---- 5b. dry run touches nothing -------------------------------------------------------------------------
    $before = $state( $H );
    $nBatches = count( $batchIds );
    sleep( 1 ); // post_modified has one-second resolution: a real write would be visible
    $d = $call( 'edit_theme_layout', [ 'layout_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-V2', 'dry_run' => true ] );
    $check( null === $d['error'] && true === ( $d['result']['dry_run'] ?? null ) && false === ( $d['result']['saved'] ?? null ) && true === ( $d['result']['valid'] ?? null ) && 1 === ( $d['result']['replaced'] ?? null ), 'edit_theme_layout dry_run: a valid preview, saved false, replaced 1' );
    $after = $state( $H );
    $check( $after === $before && 0 === $after['history'] && count( $batchIds ) === $nBatches, 'the dry run left post_content, post_modified, history and the batch log untouched' );
    $d = $call( 'update_theme_layout', [ 'layout_id' => $H, 'content' => $layout( 'AIED-HEADER-V2' ), 'dry_run' => true ] );
    $check( null === $d['error'] && false === ( $d['result']['saved'] ?? null ) && $state( $H ) === $before, 'update_theme_layout dry_run: preview only, state untouched' );

    // ---- 5c. edit the header, undo it ------------------------------------------------------------------------
    $e = $call( 'edit_theme_layout', [ 'layout_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-V2' ] );
    $check( null === $e['error'] && true === ( $e['result']['changed'] ?? null ) && true === ( $e['result']['snapshot']['stored'] ?? null ) && true === ( $e['result']['batch']['undoable'] ?? null ), 'edit_theme_layout: changed, snapshot stored, batch undoable (the cache-clear path did not fatal)' );
    clean_post_cache( $H );
    $check( false !== strpos( get_post( $H )->post_content, 'AIED-HEADER-V2' ) && false === strpos( get_post( $H )->post_content, 'AIED-HEADER-V1' ), 'the stored header now contains AIED-HEADER-V2' );
    $check( 1 === $rows( $H ), 'exactly one history row exists for the header' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $e['result']['batch']['id'] ] );
    clean_post_cache( $H );
    $check( null === $u['error'] && true === ( $u['result']['fully_undone'] ?? null ) && get_post( $H )->post_content === $layout( 'AIED-HEADER-V1' ), 'undo_batch puts the header back byte for byte' );
    $check( true === ( $u['result']['undo_batch']['undoable'] ?? null ), 'the undo is itself undoable' );

    // ---- 5d. replace the footer, byte-exact, undo ------------------------------------------------------------
    $new = $layout( 'AIED-FOOTER-V2' );
    $up  = $call( 'update_theme_layout', [ 'layout_id' => $F, 'content' => $new ] );
    clean_post_cache( $F );
    $check( null === $up['error'] && 'footer' === ( $up['result']['layout']['kind'] ?? null ) && get_post( $F )->post_content === $new, 'update_theme_layout on the footer: stored byte for byte' );
    $uf = $call( 'undo_batch', [ 'batch_id' => (int) $up['result']['batch']['id'] ] );
    clean_post_cache( $F );
    $check( true === ( $uf['result']['fully_undone'] ?? null ) && get_post( $F )->post_content === $layout( 'AIED-FOOTER-V1' ), 'undo_batch puts the footer back byte for byte' );

    // ---- 5e. an invalid layout and custom CSS are refused, nothing changes -----------------------------------
    $hb  = $state( $H );
    $bad = $call( 'update_theme_layout', [ 'layout_id' => $H, 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $layout( 'x' ) ) ] );
    $check( $errHas( $bad, 'The layout was not saved' ), 'an unknown module is refused with the violations' );
    $css = str_replace( '"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '"builderVersion":"5.14.0","css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}} /--><!-- /wp:divi/column', $layout( 'x' ) );
    $bad = $call( 'update_theme_layout', [ 'layout_id' => $H, 'content' => $css ] );
    $check( $errHas( $bad, 'CUSTOM_CSS_NOT_ALLOWED' ), 'custom CSS in a layout is refused by the shared gate' );
    $check( $state( $H ) === $hb, 'the refused writes changed nothing (content, post_modified, history)' );

    // ---- 5f. everything else is refused ----------------------------------------------------------------------
    $PG = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied verify page', 'post_content' => wp_slash( $layout( 'AIED-PAGE-V1' ) ) ] );
    $created[] = $PG;
    $refused = [ 'orphan header' => $O, 'draft-only layout' => $DL, 'body layout' => $B, 'template' => $T, 'live Theme Builder post' => $tb, 'ordinary post' => $P, 'page' => $PG ];
    foreach ( $refused as $name => $rid ) {
        $b = $state( $rid );
        foreach ( [
            'list_theme_layouts'  => [ 'layout_id' => $rid ],
            'update_theme_layout' => [ 'layout_id' => $rid, 'content' => $layout( 'Hijack' ) ],
            'edit_theme_layout'   => [ 'layout_id' => $rid, 'find' => 'AIED', 'replace' => 'X', 'expect_count' => 1 ],
        ] as $tool => $args ) {
            $check( $errHas( $call( $tool, $args ), 'not an editable header or footer' ), "{$tool} on the {$name} is refused as not an editable header or footer" );
        }
        $check( $state( $rid ) === $b && 0 === $state( $rid )['history'], "the {$name} is unchanged: same content, same post_modified, no history rows" );
    }

    // ---- 5g. undo never overwrites a layout changed since ----------------------------------------------------
    $e2 = $call( 'edit_theme_layout', [ 'layout_id' => $F, 'find' => 'AIED-FOOTER-V1', 'replace' => 'AIED-FOOTER-V3' ] );
    wp_update_post( wp_slash( [ 'ID' => $F, 'post_content' => $layout( 'EDITED-IN-THE-BUILDER' ) ] ) );
    $u2 = $call( 'undo_batch', [ 'batch_id' => (int) $e2['result']['batch']['id'] ] );
    clean_post_cache( $F );
    $check( 'changed_since' === ( $u2['result']['items'][0]['result'] ?? null ) && get_post( $F )->post_content === $layout( 'EDITED-IN-THE-BUILDER' ), 'undo skips a footer that was edited afterwards and never overwrites it' );

    // ---- 5h. the three permission layers -----------------------------------------------------------------------
    $headerBefore = $state( $H );
    $probe        = static function ( string $why ) use ( $call, $H, $layout, $errHas, $check, $state, $headerBefore ): void {
        foreach ( [
            'list_theme_layouts'  => [],
            'update_theme_layout' => [ 'layout_id' => $H, 'content' => $layout( 'NOPE' ) ],
            'edit_theme_layout'   => [ 'layout_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-NOPE' ],
        ] as $tool => $args ) {
            $check( $errHas( $call( $tool, $args ), 'permission' ), "{$why}: {$tool} is refused with a permission error" );
        }
        $check( $state( $H ) === $headerBefore, "{$why}: the header is unchanged" );
    };
    // layer 1: no edit_theme_options (the registry refuses before the tool runs)
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_theme_options' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        $check( ! current_user_can( 'edit_theme_options' ) && current_user_can( 'edit_others_posts' ) && et_pb_is_allowed( 'theme_builder' ), 'setup: only edit_theme_options is gone' );
        $probe( 'without edit_theme_options' );
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }
    // layer 2: no edit_others_posts
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_others_posts' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        $check( ! current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_theme_options' ), 'setup: edit_others_posts is gone, edit_theme_options still holds' );
        $probe( 'without edit_others_posts' );
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }
    $check( current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_theme_options' ), 'the capability filters were removed again' );
    // layer 3: Divi's Role Editor denies theme_builder
    try {
        $settings                                   = is_array( $roleExisted ? $roleBefore : [] ) ? ( $roleExisted ? $roleBefore : [] ) : [];
        $settings['administrator']                  = isset( $settings['administrator'] ) && is_array( $settings['administrator'] ) ? $settings['administrator'] : [];
        $settings['administrator']['theme_builder'] = 'off';
        update_option( $roleOption, $settings );
        unset( $GLOBALS['et_pb_role_settings'] ); // Divi caches the option in this global
        wp_cache_flush();
        $check( ! et_pb_is_allowed( 'theme_builder' ) && current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_theme_options' ), 'setup: Divi now denies theme_builder while the WordPress capabilities still hold' );
        $probe( 'Role Editor theme_builder off' );
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

    // ---- 5i. the template assignment is untouched -------------------------------------------------------------
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
        if ( [] !== $batchIds ) {
            $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
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
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
$check( 0 === $left, "cleanup: none of the batch rows this run created remain ({$left} left)" );
$check( $roleExisted ? $roleNow === $roleBefore : $roleSentinel === $roleNow, "cleanup: {$roleOption} is " . ( $roleExisted ? 'unchanged' : 'not existing, as at the start' ) );
$check( (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbBefore && (int) et_theme_builder_get_theme_builder_post_id( false, false ) === $draftTbBefore, 'cleanup: the live and draft Theme Builder post ids are what they were at the start' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro theme layout tools verified through the real registry.' );
