<?php
/**
 * Real-site check of the Pro manage_theme_template tool on Divi's own Theme Builder: the EVIDENCE GATE for that tool.
 * If any check FAILS, the tool is cut from the release (plan Task 8). It proves, through the real ToolRegistry:
 * assign / enable / disable change exactly the field asked for (every other meta, the title and the layouts are
 * byte-identical), Divi's own resolver (et_theme_builder_get_template_layouts) then really applies / stops applying the
 * template to a throwaway page, undo puts the whole state back (the template is identical to the start), the preview
 * token is required and bound to the arguments and the state, a template changed since is never overwritten, only live
 * templates can be managed (not orphans, draft-Theme-Builder templates, layouts, posts), create / duplicate / delete do
 * not exist, the permission layers each refuse, and nothing outside the one template changes.
 *
 * Run: make verify-pro-theme-templates   (needs `make up`, the free plugin and the Pro add-on active on the dev site)
 *
 * Builds throwaway fixtures with Divi's own functions (the throwaway template applies to nothing until the script
 * assigns it to a throwaway page) and removes exactly what it created. WARNING: it records about 8 batches; the batch
 * store keeps only the newest 20, so on a site that already has batches it evicts the oldest real ones. Dev sites only.
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
    $liveBackupAfterFix = get_option( $backupOption( $tb ), null );

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

    // ---- 5. the template tool, through the real registry ------------------------------------------------------
    $store = \AiEditorDivi5\Pro\ThemeBuilder\TemplateStore::class;
    $metaT0 = $metaSnapshot( $T );
    $titleT0 = get_post( $T )->post_title;
    $PGT = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied verify target page', 'post_content' => '' ] );
    $created[] = $PGT;
    $cond      = "singular:post_type:page:id:{$PGT}";
    $headerOf  = static function () use ( $PGT ): int {
        $layouts = et_theme_builder_get_template_layouts( ET_Theme_Builder_Request::from_post( $PGT ), false, false );

        return (int) ( $layouts['et_header_layout']['id'] ?? 0 );
    };
    $sameExcept = static function ( array $a, array $b, array $except ): bool {
        foreach ( array_merge( $except, [ '_et_onboarding_created' ] ) as $k ) {
            unset( $a[ $k ], $b[ $k ] );
        }

        return $a === $b;
    };
    $check( function_exists( 'et_theme_builder_get_template_layouts' ) && class_exists( 'ET_Theme_Builder_Request' ), 'setup: Divi\'s own template resolver is available' );
    $check( $headerOf() !== $H, 'before: Divi does not apply the throwaway template to the throwaway page' );

    // token rules
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'dry_run' => true ] );
    $check( null === $d['error'] && false === ( $d['result']['changed'] ?? null ) && is_string( $d['result']['token'] ?? null ) && [ 'use_on' ] === array_keys( $d['result']['would_change'] ?? [] ), 'assign dry_run: reports would_change use_on and returns a token' );
    $check( $metaSnapshot( $T ) === $metaT0, 'the dry run changed nothing' );
    $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ] ] ), 'needs the token' ), 'a real run without a token is refused' );
    $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond . '2' ], 'token' => $d['result']['token'] ] ), 'needs the token' ), 'a token for other arguments is refused' );
    $check( $metaSnapshot( $T ) === $metaT0, 'the refused calls changed nothing' );

    // a token is bound to the template's STATE: change the template behind the tool's back, the old token is refused
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'dry_run' => true ] );
    delete_post_meta( $T, '_et_exclude_from' );
    add_post_meta( $T, '_et_exclude_from', 'archive:all' );
    clean_post_cache( $T );
    $stale = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'token' => $d['result']['token'] ?? '' ] );
    clean_post_cache( $T );
    $check( $errHas( $stale, 'needs the token' ) && get_post_meta( $T, '_et_use_on', false ) === array_values( (array) ( $metaT0['_et_use_on'] ?? [] ) ), 'a token taken before the template changed is refused and _et_use_on is unchanged' );
    delete_post_meta( $T, '_et_exclude_from' );
    foreach ( (array) ( $metaT0['_et_exclude_from'] ?? [] ) as $v ) {
        add_post_meta( $T, '_et_exclude_from', $v );
    }
    clean_post_cache( $T );
    $check( $metaSnapshot( $T ) === $metaT0, 'the template is restored to its original state after the stale-token probe' );
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'dry_run' => true ] );

    // assign
    $assignToken = $d['result']['token'];
    $a = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'token' => $assignToken ] );
    $check( null === $a['error'] && true === ( $a['result']['changed'] ?? null ) && true === ( $a['result']['batch']['undoable'] ?? null ), 'assign: changed, batch undoable' );
    clean_post_cache( $T );
    $metaA = $metaSnapshot( $T );
    $check( [ $cond ] === array_values( (array) ( $metaA['_et_use_on'] ?? [] ) ), 'assign: _et_use_on is exactly the requested condition' );
    $check( $sameExcept( $metaT0, $metaA, [ '_et_use_on' ] ) && get_post( $T )->post_title === $titleT0, 'assign: every other meta (default, enabled, the three layouts and their flags, autogenerated title) and the title are identical' );
    $check( $headerOf() === $H, 'assign: Divi\'s own resolver now applies the template (its header) to the throwaway page' );
    $unusedUndo = (int) $a['result']['batch']['id'];

    // disable / enable
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'dry_run' => true ] );
    $x = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'token' => $d['result']['token'] ?? '' ] );
    $check( null === $x['error'] && true === ( $x['result']['changed'] ?? null ), 'disable: changed' );
    clean_post_cache( $T );
    $check( '0' === (string) get_post_meta( $T, '_et_enabled', true ) && $sameExcept( $metaA, $metaSnapshot( $T ), [ '_et_enabled' ] ), 'disable: only _et_enabled changed' );
    $check( $headerOf() !== $H, 'disable: Divi\'s own resolver stops applying the template to the throwaway page' );
    $disableBatch = (int) $x['result']['batch']['id'];

    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'enable', 'dry_run' => true ] );
    $x = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'enable', 'token' => $d['result']['token'] ?? '' ] );
    clean_post_cache( $T );
    $check( null === $x['error'] && '1' === (string) get_post_meta( $T, '_et_enabled', true ) && $headerOf() === $H, 'enable: back on, and Divi\'s resolver applies it again' );
    $check( $metaSnapshot( $T ) !== [] && $sameExcept( $metaA, $metaSnapshot( $T ), [] ), 'disable then enable: the template is identical to after the assign' );
    $enableBatch = (int) $x['result']['batch']['id'];

    // a no-op records nothing
    $n = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'enable' ] );
    $check( null === $n['error'] && false === ( $n['result']['changed'] ?? null ) && ! isset( $n['result']['batch'] ), 'enabling an enabled template changes and records nothing' );

    // undo walks back to the start
    $u = $call( 'undo_batch', [ 'batch_id' => $enableBatch ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ), 'undo of the enable (back to disabled): fully undone' );
    $u = $call( 'undo_batch', [ 'batch_id' => $disableBatch ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ), 'undo of the disable (back to enabled): fully undone' );
    $u = $call( 'undo_batch', [ 'batch_id' => $unusedUndo ] );
    clean_post_cache( $T );
    $check( true === ( $u['result']['fully_undone'] ?? null ) && $sameExcept( $metaT0, $metaSnapshot( $T ), [] ) && get_post( $T )->post_title === $titleT0, 'undo of the assign: the template is identical to its state before this script touched it' );
    $check( $headerOf() !== $H, 'after undo: Divi no longer applies the template to the throwaway page' );
    $replay = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'token' => $assignToken ] );
    clean_post_cache( $T );
    $check( $errHas( $replay, 'needs the token' ) && $sameExcept( $metaT0, $metaSnapshot( $T ), [] ), 'the spent token cannot assign again after the undo put the template back' );
    $diff = array_diff_key( $metaSnapshot( $T ), $metaT0 );
    WP_CLI::log( 'INFO  meta keys Divi\'s own function added that were not there before: ' . ( [] === $diff ? 'none' : implode( ', ', array_keys( $diff ) ) ) );

    // a template changed since is never overwritten
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'dry_run' => true ] );
    $x = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'token' => $d['result']['token'] ?? '' ] );
    delete_post_meta( $T, '_et_use_on' );
    add_post_meta( $T, '_et_use_on', 'archive:all' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $x['result']['batch']['id'] ] );
    $check( 'changed_since' === ( $u['result']['items'][0]['result'] ?? null ) && '0' === (string) get_post_meta( $T, '_et_enabled', true ), 'undo skips a template that was edited afterwards and never overwrites it' );
    delete_post_meta( $T, '_et_use_on' );
    foreach ( (array) ( $metaT0['_et_use_on'] ?? [] ) as $v ) {
        add_post_meta( $T, '_et_use_on', $v );
    }
    update_post_meta( $T, '_et_enabled', '1' );
    clean_post_cache( $T );

    // only live templates, and no create / duplicate / delete
    foreach ( [ 'a draft-Theme-Builder template' => $DT, 'a layout' => $H, 'a page' => $PGT, 'the Theme Builder post' => $tb, 'a missing post' => 99999999 ] as $name => $rid ) {
        $b = 99999999 === $rid ? [] : $state( $rid );
        $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $rid, 'action' => 'disable', 'dry_run' => true ] ), 'not a template of the live Theme Builder' ) && ( 99999999 === $rid || $state( $rid ) === $b ), "{$name} is refused and unchanged" );
    }
    foreach ( [ 'create', 'duplicate', 'delete' ] as $action ) {
        $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => $action ] ), 'must be one of' ), "action {$action} does not exist" );
    }

    // permission layers
    $metaNow = $metaSnapshot( $T );
    $probe   = static function ( string $why ) use ( $call, $T, $errHas, $check, $metaSnapshot, $metaNow ): void {
        $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'dry_run' => true ] ), 'permission' ), "{$why}: manage_theme_template is refused with a permission error" );
        $check( $metaSnapshot( $T ) === $metaNow, "{$why}: the template is unchanged" );
    };
    foreach ( [ 'edit_theme_options', 'edit_others_posts' ] as $cap ) {
        $strip = static fn ( $caps ) => array_merge( (array) $caps, [ $cap => false ] );
        add_filter( 'user_has_cap', $strip );
        try {
            $probe( "without {$cap}" );
        } finally {
            remove_filter( 'user_has_cap', $strip );
        }
    }
    try {
        $settings                                   = is_array( $roleExisted ? $roleBefore : [] ) ? ( $roleExisted ? $roleBefore : [] ) : [];
        $settings['administrator']                  = isset( $settings['administrator'] ) && is_array( $settings['administrator'] ) ? $settings['administrator'] : [];
        $settings['administrator']['theme_builder'] = 'off';
        update_option( $roleOption, $settings );
        unset( $GLOBALS['et_pb_role_settings'] );
        wp_cache_flush();
        $probe( 'Role Editor theme_builder off' );
    } finally {
        if ( $roleExisted ) {
            update_option( $roleOption, $roleBefore );
        } else {
            delete_option( $roleOption );
        }
        unset( $GLOBALS['et_pb_role_settings'] );
        wp_cache_flush();
    }
    // edit_post is a META capability: refuse it for this one template through map_meta_cap, as a role plugin would
    $denyOne = static fn ( $caps, $cap, $uid, $args ) => ( 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $T ) ? [ 'do_not_allow' ] : $caps;
    add_filter( 'map_meta_cap', $denyOne, 10, 4 );
    try {
        $check( ! current_user_can( 'edit_post', $T ) && current_user_can( 'edit_post', $H ), 'setup: edit_post is refused for this template only' );
        $probe( 'without edit_post on the template' );
    } finally {
        remove_filter( 'map_meta_cap', $denyOne, 10 );
    }
    $check( current_user_can( 'edit_post', $T ), 'the map_meta_cap filter was removed again' );

    // nothing outside the one template changed
    $check( $metaSnapshot( $DT ) === $metaDT, 'the draft template meta is identical' );
    $check( get_post_meta( $tb, '_et_template', false ) === $tbSnapshot && get_post_meta( $draftTb, '_et_template', false ) === $draftTbSnap, 'the Theme Builder posts still link exactly the same templates' );
    $check( $liveBackupAfterFix === get_option( $backupOption( $tb ), null ), 'Divi\'s template-mapping backup option was never touched by the tool' );
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
if ( $liveTbBefore > 0 ) {
    $check( get_post_meta( $liveTbBefore, '_et_template', false ) === $tbTemplatesBefore, 'cleanup: the pre-existing live Theme Builder links exactly its starting templates' );
    $check( get_option( $backupOption( $liveTbBefore ), null ) === $liveBackupBefore, 'cleanup: the live template-mapping backup option equals its baseline' );
}
if ( $draftTbBefore > 0 ) {
    $check( get_post_meta( $draftTbBefore, '_et_template', false ) === $draftTemplatesBefore, 'cleanup: the pre-existing draft Theme Builder links exactly its starting templates' );
    $check( get_option( $backupOption( $draftTbBefore ), null ) === $draftBackupBefore, 'cleanup: the draft template-mapping backup option equals its baseline' );
}
$check( $historyGone, 'cleanup: no history rows remain for the throwaway layouts' );
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
$check( 0 === $left, "cleanup: none of the batch rows this run created remain ({$left} left)" );
$check( $roleExisted ? $roleNow === $roleBefore : $roleSentinel === $roleNow, "cleanup: {$roleOption} is " . ( $roleExisted ? 'unchanged' : 'not existing, as at the start' ) );
$check( (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbBefore && (int) et_theme_builder_get_theme_builder_post_id( false, false ) === $draftTbBefore, 'cleanup: the live and draft Theme Builder post ids are what they were at the start' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro manage_theme_template verified on the real Divi Theme Builder.' );
