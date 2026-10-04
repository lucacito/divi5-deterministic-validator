<?php
/**
 * Real-site check of build_site (whole-site generation as one undoable build): the real tool, the real batch table, the real
 * validator, WordPress's own menus and options. It builds a throwaway three-page site, launches it, looks at the live menu as
 * an anonymous visitor, and undoes it; it also abandons a build, edits a page behind a build's back, and takes a slug before a
 * launch. Self-cleaning: it restores the front page options and the Main Menu and its location, deletes every page and batch row
 * it created, and proves the baseline afterwards.
 *
 * WARNING: opening a build trims the batch store to the newest 20, so on a site that already has batches it evicts the oldest real
 * ones. Run it on a throwaway dev site only (the dev guard refuses any other host).
 *
 * Run: make verify-pro-build-site   (needs `make up`, the free plugin and the Pro add-on active; no licence is needed: the tool is
 * called directly, not through the licence-gated registry)
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\Tools\BuildSite;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( BuildSite::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

global $wpdb;
$location = MenuBuilder::targetLocation();
$orig     = [
    'front_val' => FrontPageKind::read(),
    'mods' => get_theme_mod( 'nav_menu_locations', null ),
    'menu' => MenuBuilder::snapshot( $location ),
    'pages' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), // phpcs:ignore
];
$pages    = [];
$batchIds = [];

BatchTable::ensureReady( true );
$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );
$log  = Batches::log();
$undo = Batches::undo();
$tool = new BuildSite( $log );

$layout = static fn ( string $word ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$add = static function ( string $title, string $slug, array $extra = [] ) use ( $tool, $layout, &$pages, &$batchIds ): array {
    $r = $tool->run( [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => $layout( $title ) ] + $extra );
    $pages[]    = $r['page']['id'];
    $batchIds[] = $r['build']['id'];

    return $r;
};
$refusal = static function ( callable $fn ): string {
    try {
        $fn();
    } catch ( \InvalidArgumentException $e ) {
        return $e->getMessage();
    }

    return '';
};
$menuTitles = static function () use ( $location ): array {
    $menu = wp_get_nav_menu_object( MenuBuilder::MENU_NAME );

    return $menu ? array_map( static fn ( $o ) => (string) $o->title, array_values( (array) wp_get_nav_menu_items( (int) $menu->term_id ) ) ) : [];
};
$sameSettings = static fn (): bool => FrontPageKind::read() === $orig['front_val'] && MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ) === MenuBuilder::canonical( $orig['menu'] );

try {
    // ---- building: valid, invalid, duplicate, one open build ----------------------------------------------------
    $a = $add( 'AIED Verify Home', 'aied-verify-home', [ 'is_front' => true ] );
    $homeId = $a['page']['id'];
    $buildId = $a['build']['id'];
    $check( true === $a['created'] && 'draft' === get_post_status( $homeId ) && 'open' === $a['build']['state'], 'add_page: the first page opens a build and is a DRAFT' );
    $check( 'on' === get_post_meta( $homeId, '_et_pb_use_divi_5', true ) && 'aied-verify-home' === get_post( $homeId )->post_name, 'the page carries the Divi 5 flags and its planned slug' );
    $row = $log->find( $buildId );
    $check( null !== $row && 'building' === $row['status'] && 'page_created' === ( $row['items'][0]['kind'] ?? '' ), 'the build is ONE batch row with status building (real table)' );

    $bad = $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'build_id' => $buildId, 'title' => 'AIED Verify Bad', 'slug' => 'aied-verify-bad', 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $layout( 'x' ) ) ] ) );
    $check( false !== strpos( $bad, 'The page was not created' ) && false !== strpos( $bad, 'not-a-module' ), 'an invalid page is refused with the exact violations' );
    $check( [] === get_posts( [ 'post_type' => 'page', 'name' => 'aied-verify-bad', 'post_status' => 'any', 'fields' => 'ids' ] ), 'and nothing was created for it' );
    $dup = $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'build_id' => $buildId, 'title' => 'AIED Verify Home 2', 'slug' => 'aied-verify-home', 'content' => $layout( 'x' ) ] ) );
    $check( false !== strpos( $dup, 'page #' . $homeId ), 'a slug already taken by a draft of the build is refused, naming that page' );
    $noId = $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'title' => 'AIED Verify About', 'slug' => 'aied-verify-about', 'content' => $layout( 'x' ) ] ) );
    $check( false !== strpos( $noId, '"build_id": ' . $buildId ), 'a second call without build_id is refused and names the open build' );

    $b = $add( 'AIED Verify About', 'aied-verify-about', [ 'build_id' => $buildId, 'menu_label' => 'About' ] );
    $c = $add( 'AIED Verify Hidden', 'aied-verify-hidden', [ 'build_id' => $buildId, 'in_menu' => false ] );
    $aboutId = $b['page']['id'];
    $hiddenId = $c['page']['id'];
    $s = $tool->run( [ 'action' => 'status' ] );
    $check( 3 === $s['build']['pages'] && 2 === $s['build']['in_menu'] && $homeId === $s['build']['front_page'] && [ 'draft', 'draft', 'draft' ] === array_column( $s['pages'], 'status' ), 'status lists the three drafts in add order with the menu and front flags' );
    $check( $sameSettings(), 'while pages are only added the live front page and the live menu are untouched' );

    // ---- launch: preview, token, real run ------------------------------------------------------------------------
    $d = $tool->run( [ 'action' => 'launch', 'build_id' => $buildId, 'dry_run' => true ] );
    $check( 3 === $d['would_publish'] && 0 === $d['would_skip'] && is_string( $d['token'] ) && [ $homeId, $aboutId, $hiddenId ] === array_column( $d['pages'], 'id' ), 'launch dry run: three pages would go live, and a token is returned' );
    $check( 'draft' === get_post_status( $homeId ) && $sameSettings(), 'the dry run changed nothing' );
    $check( false !== strpos( $refusal( static fn () => $tool->run( [ 'action' => 'launch', 'build_id' => $buildId ] ) ), 'needs the token' ), 'a real launch without the token is refused' );

    $l = $tool->run( [ 'action' => 'launch', 'build_id' => $buildId, 'token' => $d['token'] ] );
    $check( true === $l['changed'] && 3 === count( $l['published'] ) && [] === $l['skipped'] && 'launched' === $l['build']['state'], 'launch: three pages published, none skipped, the build is launched' );
    $check( 'publish' === get_post_status( $homeId ) && 'publish' === get_post_status( $aboutId ) && 'publish' === get_post_status( $hiddenId ), 'the real post statuses are publish' );
    $check( 'page' === get_option( 'show_on_front' ) && $homeId === (int) get_option( 'page_on_front' ), 'the front page option points at the flagged page' );
    $check( [ 'AIED Verify Home', 'About' ] === $menuTitles(), 'the Main Menu is rebuilt from the in_menu pages, in add order, with the menu labels' );
    $check( $l['published'][1]['link'] === get_permalink( $aboutId ) && false === strpos( $l['published'][1]['link'], 'page_id=' ), 'the result carries the real pretty permalink of a published page' );
    if ( '' !== $location ) {
        wp_set_current_user( 0 );
        $html = (string) wp_nav_menu( [ 'theme_location' => $location, 'echo' => false, 'container' => false, 'fallback_cb' => false ] );
        wp_set_current_user( 1 );
        $check( false !== strpos( $html, get_permalink( $aboutId ) ) && false === strpos( $html, 'page_id=' . $aboutId ), 'an anonymous visitor sees the About link with its real permalink, not a dead ?page_id link' );
    }
    $row = $log->find( $buildId );
    $check( 'applied' === $row['status'] && [ 'page_created', 'page_created', 'page_created', 'post_status', 'post_status', 'post_status', 'front_page', 'menu' ] === array_column( $row['items'], 'kind' ), 'one batch: creation, statuses, front page, menu' );
    $check( false !== strpos( $refusal( static fn () => $tool->run( [ 'action' => 'launch', 'build_id' => $buildId, 'token' => $d['token'] ] ) ), 'finished' ), 'a launched build cannot be launched again (the token is spent)' );
    $check( false !== strpos( $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'build_id' => $buildId, 'title' => 'Late', 'slug' => 'aied-verify-late', 'content' => $layout( 'x' ) ] ) ), 'finished (launched)' ), 'a page cannot be added to a launched build' );

    // ---- undo of the launched build -------------------------------------------------------------------------------
    $u = $undo->undo( $buildId );
    if ( isset( $u['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $u['undo_batch']['id'];
    }
    $check( true === $u['fully_undone'] && 8 === $u['restored'], 'undo_batch of the launched build restores all eight items' );
    $check( $sameSettings(), 'the front page options and the Main Menu are exactly as before the build' );
    $check( 'trash' === get_post_status( $homeId ) && 'trash' === get_post_status( $aboutId ) && 'trash' === get_post_status( $hiddenId ), 'the created pages are in the trash (back to draft first, then trashed)' );
    $check( 'undone' === $log->find( $buildId )['status'], 'the build batch is marked undone' );

    // ---- abandon an open build, then an edit behind a build\'s back -------------------------------------------------
    $e = $add( 'AIED Verify Abandoned', 'aied-verify-abandoned' );
    $u = $undo->undo( $e['build']['id'] );
    if ( isset( $u['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $u['undo_batch']['id'];
    }
    $check( true === $u['fully_undone'] && 'trash' === get_post_status( $e['page']['id'] ) && null === $log->openBuild( get_current_user_id() ), 'an open build can be abandoned with undo_batch, and a new one can start' );

    $f = $add( 'AIED Verify Edited', 'aied-verify-edited' );
    wp_update_post( wp_slash( [ 'ID' => $f['page']['id'], 'post_content' => $layout( 'Edited in the builder' ) ] ) );
    $u = $undo->undo( $f['build']['id'] );
    if ( isset( $u['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $u['undo_batch']['id'];
    }
    $check( false === $u['fully_undone'] && 'changed_since' === ( $u['items'][0]['result'] ?? '' ) && 'draft' === get_post_status( $f['page']['id'] ), 'a page edited after the build is skipped by undo (changed_since) and never trashed' );
    $undo->undo( $f['build']['id'], true );

    // ---- a slug taken between add_page and launch -----------------------------------------------------------------------
    $g = $add( 'AIED Verify Clash', 'aied-verify-clash' );
    // Another DRAFT with the same slug (core keeps a draft's slug as given; it only resolves clashes when a page is published, and
    // then renames the page being published): the realistic way a slug gets taken between add_page and launch.
    $taker = wp_insert_post( wp_slash( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'AIED Verify Taker', 'post_name' => 'aied-verify-clash', 'post_content' => '' ] ), true );
    $pages[] = (int) $taker;
    $p = $tool->run( [ 'action' => 'launch', 'build_id' => $g['build']['id'], 'dry_run' => true ] );
    $check( 0 === $p['would_publish'] && 'slug_taken' === ( $p['pages'][0]['reason'] ?? '' ), 'launch preview: a slug taken meanwhile skips that page (slug_taken)' );
    $r = $tool->run( [ 'action' => 'launch', 'build_id' => $g['build']['id'], 'token' => $p['token'] ] );
    $check( false === $r['changed'] && 'draft' === get_post_status( $g['page']['id'] ) && 'open' === $tool->run( [ 'action' => 'status', 'build_id' => $g['build']['id'] ] )['build']['state'], 'nothing publishable: the build stays open and the page stays a draft' );
    $u = $undo->undo( $g['build']['id'] );
    if ( isset( $u['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $u['undo_batch']['id'];
    }
    $check( true === $u['fully_undone'], 'and it can be abandoned' );
} catch ( \Throwable $ex ) {
    $check( false, 'unexpected error: ' . get_class( $ex ) . ': ' . $ex->getMessage() );
} finally {
    // ---- cleanup -----------------------------------------------------------------------------------------------------------------
    FrontPageKind::set( $orig['front_val']['show_on_front'], $orig['front_val']['page_on_front'] );
    MenuBuilder::restore( $orig['menu'] );
    foreach ( array_unique( $pages ) as $id ) {
        wp_delete_post( (int) $id, true );
    }
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
    }
    delete_option( 'aied_pro_buildlock_' . get_current_user_id() );
}

$check( FrontPageKind::read() === $orig['front_val'], 'cleanup: the front page settings are as before (show_on_front and page_on_front, as the plugin reads them)' );
$check( MenuBuilder::canonical( $orig['menu'] ) === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'cleanup: the Main Menu and its location are as before' );
$check( $orig['pages'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), 'cleanup: every throwaway page is deleted' ); // phpcs:ignore
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
$check( 0 === $left, 'cleanup: every batch row this script created is deleted' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'build_site verified on the real site: build, launch, anonymous menu, undo, abandon, edited page, taken slug.' );
