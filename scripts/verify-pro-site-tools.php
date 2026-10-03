<?php
/**
 * Real-site check of the Pro site-wide tools (find_replace_site, audit_site), driven through the REAL ToolRegistry
 * (so the capability gate is part of what is proven), the real batch table, the real free history table and Divi's
 * own Theme Builder posts on the Docker site. It proves: a find/replace preview touches nothing and returns a token;
 * a real run needs it; every occurrence in every matching Divi 5 page is replaced byte for byte as ONE batch that
 * undo_batch restores; classic pages, trashed pages and posts are never touched; an item changed since the preview
 * is skipped; a result that would fail the layout checks is skipped and never saved; more than 50 matches are refused
 * with the count; the live header/footer are included only with scope layouts and the Theme Builder permission; the
 * permission layers refuse. And that audit_site finds layout violations, custom code, unknown preset ids, missing and
 * unpublished internal links, pages through the whole site 25 at a time, and changes nothing.
 *
 * Run: make verify-pro-site-tools   (needs `make up`, the free plugin and the Pro add-on active on the dev site;
 * no licence is needed: the registry is built with the unlock forced)
 *
 * Builds throwaway fixtures (posts titled 'aied verify ...') and removes exactly what it created, even on failure.
 * WARNING: it records about 6 batches, and the batch store keeps only the newest 20, so on a site that already has
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

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

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
$log_kind = static fn ( int $id ): string => (string) ( \AiEditorDivi5\Pro\Batch\Batches::log()->find( $id )['items'][0]['kind'] ?? '' );
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

    // ---- 5. throwaway pages --------------------------------------------------------------------------------
    $divi = static function ( string $title, string $content, string $status = 'publish', array $extra = [] ) use ( &$created ): int {
        $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title, 'post_content' => wp_slash( $content ) ] + $extra, true );
        if ( is_wp_error( $id ) ) {
            throw new \RuntimeException( 'could not create a throwaway page: ' . $id->get_error_message() );
        }
        $created[] = (int) $id;
        update_post_meta( $id, '_et_pb_use_divi_5', 'on' );
        update_post_meta( $id, '_et_pb_use_builder', 'on' );

        return (int) $id;
    };
    $read = static function ( int $id ): string {
        clean_post_cache( $id );

        return (string) get_post( $id )->post_content;
    };
    $A1 = $divi( 'aied verify fr 1', $layout( 'Hello AIED-FR-OLD world' ) );
    $A2 = $divi( 'aied verify fr 2', $layout( 'AIED-FR-OLD and AIED-FR-OLD again' ) );
    $A3 = $divi( 'aied verify fr 3', $layout( 'Draft AIED-FR-OLD' ), 'draft' );
    $CL = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied verify classic', 'post_content' => '<p>Classic AIED-FR-OLD</p>' ] );
    $created[] = $CL;
    $TR = $divi( 'aied verify trashed', $layout( 'Trashed AIED-FR-OLD' ), 'trash' );
    $find = [ 'find' => 'AIED-FR-OLD', 'replace' => 'AIED-FR-NEW' ];
    $expected = static fn ( string $c ): string => str_replace( 'AIED-FR-OLD', 'AIED-FR-NEW', $c );
    $c0 = [ $A1 => $read( $A1 ), $A2 => $read( $A2 ), $A3 => $read( $A3 ) ];
    $check( true, 'fixtures: three Divi 5 pages (one draft), a classic page, a trashed page' );

    // ---- 5a. find_replace_site: preview, token, apply ---------------------------------------------------------
    $before = [ $state( $A1 ), $state( $A2 ), $state( $A3 ) ];
    sleep( 1 );
    $d = $call( 'find_replace_site', $find + [ 'dry_run' => true ] );
    $ids = array_column( (array) ( $d['result']['items'] ?? [] ), 'id' );
    $check( null === $d['error'] && [ $A1, $A2, $A3 ] === $ids && 3 === ( $d['result']['matched'] ?? null ) && is_string( $d['result']['token'] ?? null ), 'dry run: exactly the three Divi 5 pages match (not the classic page, not the trashed one) and a token comes back' );
    $check( 2 === (int) ( $d['result']['items'][1]['occurrences'] ?? 0 ), 'dry run counts both occurrences in page 2' );
    $check( [ $state( $A1 ), $state( $A2 ), $state( $A3 ) ] === $before, 'the dry run left content, post_modified and history untouched' );
    $check( $errHas( $call( 'find_replace_site', $find ), 'needs the token' ), 'a real run without a token is refused' );
    $check( $errHas( $call( 'find_replace_site', [ 'find' => 'AIED-FR-OLD', 'replace' => 'OTHER', 'token' => $d['result']['token'] ] ), 'needs the token' ), 'a token for other arguments is refused' );
    $r = $call( 'find_replace_site', $find + [ 'token' => $d['result']['token'] ] );
    $check( null === $r['error'] && true === ( $r['result']['changed'] ?? null ) && 3 === ( $r['result']['replaced_in'] ?? null ) && [] === ( $r['result']['skipped'] ?? null ) && true === ( $r['result']['batch']['undoable'] ?? null ), 'real run: three items replaced as one undoable batch' );
    $check( $read( $A1 ) === $expected( $c0[ $A1 ] ) && $read( $A2 ) === $expected( $c0[ $A2 ] ) && $read( $A3 ) === $expected( $c0[ $A3 ] ), 'every occurrence is replaced, byte for byte (wp_slash exact)' );
    $check( '<p>Classic AIED-FR-OLD</p>' === $read( $CL ) && false !== strpos( $read( $TR ), 'AIED-FR-OLD' ), 'the classic page and the trashed page were never touched' );
    $check( 1 === $rows( $A1 ) && 1 === $rows( $A2 ), 'each replaced page has exactly one history snapshot' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $r['result']['batch']['id'] ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ) && $read( $A1 ) === $c0[ $A1 ] && $read( $A2 ) === $c0[ $A2 ] && $read( $A3 ) === $c0[ $A3 ], 'undo_batch puts all three pages back byte for byte, fully undone' );
    $replay = $call( 'find_replace_site', $find + [ 'token' => $d['result']['token'] ] );
    $check( $errHas( $replay, 'needs the token' ) && $read( $A1 ) === $c0[ $A1 ] && $read( $A2 ) === $c0[ $A2 ] && $read( $A3 ) === $c0[ $A3 ], 'the spent token cannot apply the change again after the undo put the content back' );

    // ---- 5b. an item changed since the preview is skipped -----------------------------------------------------
    $d = $call( 'find_replace_site', $find + [ 'dry_run' => true ] );
    wp_update_post( wp_slash( [ 'ID' => $A2, 'post_content' => $layout( 'Edited in the builder AIED-FR-OLD' ) ] ) );
    $r = $call( 'find_replace_site', $find + [ 'token' => $d['result']['token'] ] );
    $check( 2 === ( $r['result']['replaced_in'] ?? null ) && 'changed_since_preview' === ( $r['result']['skipped'][0]['reason'] ?? null ) && $A2 === ( $r['result']['skipped'][0]['id'] ?? null ), 'a page edited after the preview is skipped and reported; the others are replaced' );
    $check( $read( $A2 ) === $layout( 'Edited in the builder AIED-FR-OLD' ), 'the skipped page keeps the builder edit' );
    $call( 'undo_batch', [ 'batch_id' => (int) $r['result']['batch']['id'] ] );
    wp_update_post( wp_slash( [ 'ID' => $A2, 'post_content' => $c0[ $A2 ] ] ) );

    // ---- 5c. a result that would fail the checks is skipped, never saved ----------------------------------------
    $bad = [ 'find' => 'wp:divi/heading', 'replace' => 'wp:divi/not-a-module', 'page_ids' => [ $A1 ] ];
    $d   = $call( 'find_replace_site', $bad + [ 'dry_run' => true ] );
    $check( 1 === ( $d['result']['would_skip'] ?? null ) && false === ( $d['result']['items'][0]['valid'] ?? null ) && ! empty( $d['result']['items'][0]['violations'] ), 'preview: the page that would become invalid is flagged with violation codes' );
    $r = $call( 'find_replace_site', $bad + [ 'token' => $d['result']['token'] ] );
    $check( false === ( $r['result']['changed'] ?? null ) && 'invalid_result' === ( $r['result']['skipped'][0]['reason'] ?? null ) && $read( $A1 ) === $c0[ $A1 ], 'real run: skipped as invalid_result and never saved' );

    // ---- 5d. more than fifty matches are refused with the count -----------------------------------------------
    $many = [];
    for ( $i = 0; $i < 51; $i++ ) {
        $many[] = $divi( 'aied verify cap ' . $i, $layout( 'AIED-FR-CAP' ) );
    }
    $capRefusal = $call( 'find_replace_site', [ 'find' => 'AIED-FR-CAP', 'replace' => 'X', 'dry_run' => true ] );
    $check( $errHas( $capRefusal, '51 items contain that text; the limit is 50' ), 'fifty-one matches are refused with the count, not truncated' );
    $d = $call( 'find_replace_site', [ 'find' => 'AIED-FR-CAP', 'replace' => 'X', 'dry_run' => true, 'page_ids' => array_slice( $many, 0, 50 ) ] );
    $check( null === $d['error'] && 50 === ( $d['result']['matched'] ?? null ), 'exactly fifty is allowed (preview only)' );

    // ---- 5e. layouts: only with scope and the Theme Builder permission --------------------------------------------
    $d = $call( 'find_replace_site', [ 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-FR', 'dry_run' => true ] );
    $check( 0 === ( $d['result']['matched'] ?? -1 ), 'scope pages (default) never matches a header or footer' );
    $L = [ 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-FR', 'scope' => 'layouts' ];
    $d = $call( 'find_replace_site', $L + [ 'dry_run' => true ] );
    $check( [ $H ] === array_column( (array) ( $d['result']['items'] ?? [] ), 'id' ) && 'header' === ( $d['result']['items'][0]['kind'] ?? null ), 'scope layouts matches the live header' );
    $r = $call( 'find_replace_site', $L + [ 'token' => $d['result']['token'] ] );
    $check( true === ( $r['result']['changed'] ?? null ) && $read( $H ) === $layout( 'AIED-HEADER-FR' ) && 'theme_layout_content' === ( $r['result']['batch'] ? $log_kind( (int) $r['result']['batch']['id'] ) : '' ), 'the header is replaced and recorded as a theme_layout_content item' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $r['result']['batch']['id'] ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ) && $read( $H ) === $layout( 'AIED-HEADER-V1' ), 'undo restores the header byte for byte' );
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_theme_options' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        $check( $errHas( $call( 'find_replace_site', $L ), 'permission' ), 'without edit_theme_options, scope layouts is refused' );
        $d = $call( 'find_replace_site', array_merge( $L, [ 'scope' => 'all', 'dry_run' => true ] ) );
        $check( 0 === ( $d['result']['matched'] ?? -1 ) && is_string( $d['result']['layouts_note'] ?? null ), 'without edit_theme_options, scope all skips the layouts and says so' );
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }

    // ---- 5f. the registry's own capability gate ---------------------------------------------------------------
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_pages' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        foreach ( [ 'find_replace_site' => $find, 'audit_site' => [] ] as $tool => $args ) {
            $check( $errHas( $call( $tool, $args ), 'permission' ), "without edit_pages, {$tool} is refused by the registry" );
        }
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }

    // ---- 5g. audit_site ------------------------------------------------------------------------------------------
    $PUB = $divi( 'aied verify audit published', $layout( 'fine' ), 'publish', [ 'post_name' => 'aied-verify-published' ] );
    $DRF = $divi( 'aied verify audit draft target', $layout( 'draft target' ), 'draft', [ 'post_name' => 'aied-verify-draft' ] );
    $linkBtn = static fn ( string $url ): string => str_replace( '<!-- /wp:divi/column -->', '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"' . $url . '"}}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column -->', $layout( 'links' ) );
    $AOK  = $divi( 'aied verify audit ok', $linkBtn( '/aied-verify-published/' ) );
    $ABAD = $divi( 'aied verify audit bad module', str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $layout( 'x' ) ) );
    $ALNK = $divi( 'aied verify audit links', $linkBtn( '/aied-verify-missing/' ) . $linkBtn( '/aied-verify-draft/' ) . $linkBtn( 'https://elsewhere.invalid/x/' ) . $linkBtn( '#top' ) );
    $ACSS = $divi( 'aied verify audit css', str_replace( '"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '"builderVersion":"5.14.0","css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}} /--><!-- /wp:divi/column', $layout( 'css' ) ) );
    $APRE = $divi( 'aied verify audit preset', str_replace( '"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '"modulePreset":["aied-verify-unknown-preset"],"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', $layout( 'preset' ) ) );
    $mine = [ $PUB, $DRF, $AOK, $ABAD, $ALNK, $ACSS, $APRE ];
    $snap = [];
    foreach ( $mine as $id ) {
        $snap[ $id ] = $state( $id );
    }
    $postsBefore  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ); // phpcs:ignore
    $batchesBefore = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ); // phpcs:ignore

    $all = [];
    $cursor = 0;
    $calls = 0;
    $total = null;
    $maxScanned = 0;
    $cursors = [];
    do {
        $a = $call( 'audit_site', [ 'scope' => 'pages', 'cursor' => $cursor ] );
        if ( null !== $a['error'] ) {
            $check( false, 'audit_site page call failed: ' . ( $a['error']['message'] ?? '?' ) );
            break;
        }
        $calls++;
        $total       = $a['result']['total_items'];
        $maxScanned  = max( $maxScanned, (int) $a['result']['scanned'] );
        foreach ( $a['result']['findings'] as $f ) {
            $all[ $f['id'] ][ $f['code'] ] = $f;
        }
        $cursor = $a['result']['next_cursor'];
        if ( null !== $cursor ) {
            $cursors[] = $cursor;
        }
    } while ( null !== $cursor && $calls < 200 );
    $diviPages = count( get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => [ [ 'key' => '_et_pb_use_divi_5', 'value' => 'on' ] ] ] ) );
    $check( $total === $diviPages && $maxScanned <= 25 && $calls === (int) ceil( $diviPages / 25 ), "audit pages through the whole site: {$diviPages} Divi 5 pages in {$calls} calls of at most 25" );
    $sorted = $cursors;
    sort( $sorted );
    $check( $cursors === $sorted && count( $cursors ) === count( array_unique( $cursors ) ) && ( [] === $cursors || get_post( end( $cursors ) ) instanceof WP_Post ), 'the audit cursor is the id of the last audited page: strictly increasing, and each one is a real page id' );
    $check( empty( $all[ $AOK ] ) && empty( $all[ $PUB ] ), 'a page with a valid layout and a link to a published page has no findings' );
    $check( ! empty( $all[ $ABAD ] ), 'a page with an unknown module has layout violations' );
    $check( isset( $all[ $ACSS ]['CUSTOM_CSS_NOT_ALLOWED'] ), 'custom CSS already stored is reported (CUSTOM_CSS_NOT_ALLOWED)' );
    $check( isset( $all[ $ALNK ]['LINK_NO_POST'] ) && false !== strpos( $all[ $ALNK ]['LINK_NO_POST']['detail'], '/aied-verify-missing/' ) && 1 === $all[ $ALNK ]['LINK_NO_POST']['count'], 'a link to a missing page is LINK_NO_POST (and the external link and #top are ignored)' );
    $check( isset( $all[ $ALNK ]['LINK_NOT_PUBLISHED'] ) && false !== strpos( $all[ $ALNK ]['LINK_NOT_PUBLISHED']['detail'], '/aied-verify-draft/ (draft)' ), 'a link to a draft page is LINK_NOT_PUBLISHED' );
    $check( isset( $all[ $APRE ]['UNKNOWN_PRESET_ID'] ) && false !== strpos( $all[ $APRE ]['UNKNOWN_PRESET_ID']['detail'], 'aied-verify-unknown-preset' ), 'a preset id this site does not have is UNKNOWN_PRESET_ID' );
    $lay = $call( 'audit_site', [ 'scope' => 'layouts' ] );
    $check( null === $lay['error'] && 2 <= (int) $lay['result']['total_items'] && in_array( $H, array_column( $lay['result']['findings'], 'id' ), true ) === false, 'scope layouts audits the live header and footer (valid fixtures: no findings for them)' );
    $same = true;
    foreach ( $mine as $id ) {
        $same = $same && $state( $id ) === $snap[ $id ];
    }
    $check( $same, 'the audit changed no page: content, post_modified and history are identical' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === $postsBefore && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ) === $batchesBefore, 'the audit created no post and no batch' ); // phpcs:ignore
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
WP_CLI::success( 'Pro site-wide tools verified through the real registry.' );
