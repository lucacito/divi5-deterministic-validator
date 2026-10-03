<?php
/**
 * Real-site check of the Pro settings tools and one-step undo: the real handlers, the real batch table and
 * WordPress's own functions. Self-cleaning: it restores the front page, Additional CSS, the Main Menu and its
 * location and the code proposals to exactly what they were, and deletes every page, batch row and proposal it created.
 *
 * WARNING: it records about 19 batches and the batch store keeps only the newest 20, so on a site that already has
 * batches it evicts the oldest real ones. Run it on a throwaway dev site only.
 *
 * Run: make verify-pro-settings   (needs `make up`, the free plugin and the Pro add-on active; no licence is needed:
 * the tools are called directly, not through the licence-gated registry)
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\Settings\CustomCss;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\Settings\PhpProposals;
use AiEditorDivi5\Pro\Tools\ListBatches;
use AiEditorDivi5\Pro\Tools\ProposePhpSnippet;
use AiEditorDivi5\Pro\Tools\PublishPage;
use AiEditorDivi5\Pro\Tools\SetCustomCss;
use AiEditorDivi5\Pro\Tools\SetFrontPage;
use AiEditorDivi5\Pro\Tools\SetPrimaryMenu;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
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

// ---- remember exactly what this script will touch --------------------------------------------
$location    = MenuBuilder::targetLocation();
$origCssPost = wp_get_custom_css_post();
$orig        = [
    'show'      => get_option( 'show_on_front', null ),
    'front'     => get_option( 'page_on_front', null ),
    'front_val' => FrontPageKind::read(),
    'css'       => (string) wp_get_custom_css(),
    'mods'      => get_theme_mod( 'nav_menu_locations', null ),
    'menu'      => MenuBuilder::snapshot( $location ),
    'proposals' => get_option( PhpProposals::OPTION, null ),
];
$pages    = [];
$batchIds = [];

BatchTable::ensureReady( true );
$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );

$log  = Batches::log();
$undo = Batches::undo();

$track = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['batch']['id'] ) ) {
        $batchIds[] = (int) $r['batch']['id'];
    }

    return $r;
};
$trackUndo = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $r['undo_batch']['id'];
    }

    return $r;
};
$makePage = static function ( string $title, string $status ) use ( &$pages ): int {
    $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title, 'post_content' => '' ], true );
    if ( is_wp_error( $id ) ) {
        // Not WP_CLI::error(): it exits at once and would skip the cleanup in the `finally` below.
        throw new \RuntimeException( 'Could not create a throwaway page: ' . $id->get_error_message() );
    }
    $pages[] = (int) $id;

    return (int) $id;
};

try {
    $draft = $makePage( 'AIED Pro verify draft', 'draft' );
    $live1 = $makePage( 'AIED Pro verify live 1', 'publish' );
    $live2 = $makePage( 'AIED Pro verify live 2', 'publish' );

    // ---- publish_page -------------------------------------------------------------------------
    $pub = new PublishPage( $log );
    $pub->run( [ 'page_id' => $draft, 'dry_run' => true ] );
    $check( 'draft' === get_post_status( $draft ), 'publish_page: a dry run leaves the page a draft' );

    $r = $track( $pub->run( [ 'page_id' => $draft ] ) );
    $check( 'publish' === get_post_status( $draft ) && true === ( $r['batch']['undoable'] ?? false ), 'publish_page: publishes the draft and records an undoable batch' );
    $row = $log->find( (int) $r['batch']['id'] );
    $check( null !== $row && 'post_status' === ( $row['items'][0]['kind'] ?? '' ) && 'draft' === ( $row['items'][0]['before'] ?? '' ), 'the batch round-trips through the real table' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && 'draft' === get_post_status( $draft ), 'undo returns the page to draft' );

    $r = $track( $pub->run( [ 'page_id' => $draft, 'publish_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 90 * DAY_IN_SECONDS ) ] ) );
    $check( 'future' === get_post_status( $draft ), 'publish_page: schedules a future page' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && 'draft' === get_post_status( $draft ), 'undo of a scheduled page returns it to draft' );

    // ---- set_front_page -----------------------------------------------------------------------
    $fp = new SetFrontPage( $log );
    $r  = $track( $fp->run( [ 'page_id' => $live1 ] ) );
    $check( 'page' === get_option( 'show_on_front' ) && $live1 === (int) get_option( 'page_on_front' ), 'set_front_page: the real options are set' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && FrontPageKind::read() === $orig['front_val'], 'undo restores the front page settings' );

    $r = $track( $fp->run( [ 'page_id' => $live2 ] ) );
    update_option( 'page_on_front', $live1 );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( 'changed_since' === ( $u['items'][0]['result'] ?? '' ) && $live1 === (int) get_option( 'page_on_front' ), 'a front page changed since is not overwritten' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'], true ) );
    $check( true === $u['fully_undone'] && FrontPageKind::read() === $orig['front_val'], 'force restores it' );

    // ---- set_custom_css -----------------------------------------------------------------------
    $css    = new SetCustomCss( $log );
    $sample = '.aied-verify::before { content: "é $1 \\0"; }';
    $r      = $track( $css->run( [ 'css' => $sample ] ) );
    $now    = (string) wp_get_custom_css();
    $check( CustomCss::extract( $now ) === $sample, 'set_custom_css: accents, $1 and a backslash are stored literally in the real Additional CSS' );
    $check( str_starts_with( $now, rtrim( $orig['css'] ) ), 'the owner\'s own CSS is untouched' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && rtrim( (string) wp_get_custom_css() ) === rtrim( $orig['css'] ), 'undo restores the Additional CSS exactly' );

    $r = $track( $css->run( [ 'css' => '.aied-a{}' ] ) );
    wp_update_custom_css_post( str_replace( '.aied-a{}', '.aied-a{ color: red }', (string) wp_get_custom_css() ) );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( 'changed_since' === ( $u['items'][0]['result'] ?? '' ), 'an edit inside the managed block is not overwritten by undo' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'], true ) );
    $check( true === $u['fully_undone'] && null === CustomCss::extract( (string) wp_get_custom_css() ), 'force removes the block' );

    // ---- set_primary_menu (the spec's riskiest tool: menu creation is unproven until this passes) --
    $check( '' !== $location, 'the theme has a menu location (' . $location . ')' );
    $menu   = new SetPrimaryMenu( $log );
    $before = MenuBuilder::canonical( MenuBuilder::snapshot( $location ) );
    $r      = $track( $menu->run( [ 'items' => [
        [ 'title' => 'AIED Live 1', 'page_id' => $live1 ],
        [ 'title' => 'AIED Live 2', 'page_id' => $live2 ],
        [ 'title' => 'AIED Link', 'url' => 'https://example.com/aied-verify' ],
    ] ] ) );
    $built = MenuBuilder::snapshot( $location );
    $check( $built['exists'] && [ 'AIED Live 1', 'AIED Live 2', 'AIED Link' ] === array_column( $built['items'], 'title' ), 'set_primary_menu: Main Menu exists with the three items in order' );
    $check( '' === $location || $built['location_menu_id'] === $built['menu_id'], 'the theme location points at the new menu' );
    $builtCanon = MenuBuilder::canonical( $built );
    $u          = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && $before === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'undo restores the previous menu state exactly' );
    $u2 = $trackUndo( $undo->undo( (int) $u['undo_batch']['id'] ) );
    $check( true === $u2['fully_undone'] && $builtCanon === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'undoing the undo brings the new menu back' );

    // ---- propose_php_snippet ------------------------------------------------------------------
    $p = ( new ProposePhpSnippet() )->run( [ 'title' => 'AIED verify', 'code' => "<?php\n// verify only, never run", 'description' => 'x' ] );
    $check( true === $p['proposed'] && false === $p['executed'], 'propose_php_snippet: stored, not executed' );
    $check( 1 === count( array_filter( PhpProposals::all(), static fn ( array $x ): bool => $x['id'] === $p['id'] ) ), 'the proposal is in the real option' );

    // ---- list_batches -------------------------------------------------------------------------
    $listed = ( new ListBatches( $log ) )->run( [ 'limit' => 20 ] )['batches'];
    $check( [] !== $listed && isset( $listed[0]['changes'] ), 'list_batches: lists the batches just made' );
} catch ( \Throwable $e ) {
    $check( false, 'unexpected exception: ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    // ---- put everything back exactly -----------------------------------------------------------
    null === $orig['show'] ? delete_option( 'show_on_front' ) : update_option( 'show_on_front', $orig['show'] );
    null === $orig['front'] ? delete_option( 'page_on_front' ) : update_option( 'page_on_front', $orig['front'] );
    $cssPost = wp_get_custom_css_post();
    if ( null === $origCssPost ) {
        if ( $cssPost ) {
            wp_delete_post( $cssPost->ID, true );
        }
    } else {
        wp_update_custom_css_post( $orig['css'] );
    }
    MenuBuilder::restore( $orig['menu'] );
    null === $orig['mods'] ? remove_theme_mod( 'nav_menu_locations' ) : set_theme_mod( 'nav_menu_locations', $orig['mods'] );
    null === $orig['proposals'] ? delete_option( PhpProposals::OPTION ) : update_option( PhpProposals::OPTION, $orig['proposals'], false );
    foreach ( $pages as $id ) {
        wp_delete_post( $id, true );
    }
    global $wpdb;
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' );
    }
}

// WordPress stores every option as a string; update_option() caches the written value, so in this same process 0 and "0" can differ in type only.
$sameOpt = static fn ( $a, $b ): bool => ( null === $a || null === $b ) ? $a === $b : (string) $a === (string) $b;
$check( $sameOpt( $orig['show'], get_option( 'show_on_front', null ) ) && $sameOpt( $orig['front'], get_option( 'page_on_front', null ) ), 'cleanup: the front page options are exactly as before' );
$check( rtrim( (string) wp_get_custom_css() ) === rtrim( $orig['css'] ), 'cleanup: Additional CSS is exactly as before' );
$check( MenuBuilder::canonical( $orig['menu'] ) === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'cleanup: the Main Menu and its location are as before' );
$check( $orig['proposals'] === get_option( PhpProposals::OPTION, null ), 'cleanup: code proposals are exactly as before' );
$gone = true;
foreach ( $pages as $id ) {
    $gone = $gone && null === get_post( $id );
}
$check( $gone, 'cleanup: every throwaway page is deleted' );
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' );
$check( 0 === $left, 'cleanup: every batch row this script created is deleted' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'The Pro settings tools and batch undo are verified on the real site.' );
