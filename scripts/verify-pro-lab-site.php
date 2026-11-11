<?php
/**
 * Real-site check of build_site with Divi 5 Lab briefs (Pro Lab plan Task 10, spec 2026-11-10-pro-lab-design.md section B): the real
 * BuildSite and LabRestyle tools (as the registry serves them, unlock forced), the real batch table, the real free Lab store and
 * the real free `lab_build` (StudioAssistant::run). In order:
 *
 *   - a throwaway administrator builds 10 brief pages; only the first brief names its language (editorial), the other nine take
 *     the build's look; page 1 carries hostile words. Each page is byte-equal to the same filled brief saved by the REAL free
 *     lab_build (action build, create:true) as a second throwaway administrator (stored bytes against stored bytes);
 *   - a brief that names another look is refused (LOOK_MISMATCH) and creates nothing;
 *   - 10 briefs are stored and linked to the 10 pages, all in the build's look (editorial + quiet-solid);
 *   - a third throwaway administrator with 45 stored briefs is refused at the 6th brief page (TOO_MANY_BRIEFS), holds 50 briefs
 *     with the 45 old ids unchanged;
 *   - the hostile words render literally on the front end (the page fetched over HTTP as its administrator);
 *   - lab_restyle over the 10 drafts to luxe (preview + token) equals the direct compile of each restyled brief saved through the
 *     same save path; the build's look follows;
 *   - launch (dry run + token): 10 published, front page and menu set;
 *   - lab_restyle without include_published skips all 10 (published); with it, restyles them;
 *   - undo_batch of the two restyles restores the pages and the briefs exactly; undo_batch of the build then trashes the pages
 *     and leaves the 10 briefs.
 *
 * Self-cleaning, like scripts/verify-pro-build-site.php: it restores the front page options and the Main Menu and its location, deletes
 * every page, brief row, user, lock option and batch row it created, and proves the baseline afterwards.
 *
 * WARNING: opening a build trims the batch store to the newest 20, so on a site that already has batches it evicts the oldest real
 * ones. Run it on a throwaway dev site only (the dev guard refuses any other host).
 *
 * Run: make verify-pro-lab-site   (needs `make up`, the free plugin with Divi 5 Lab and the Pro add-on active; no licence is needed)
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\BuildSite;
use AiEditorDivi5\Pro\Tools\LabRestyle;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StudioAssistant;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( BuildSite::class ) || ! class_exists( LabRestyle::class ) || ! class_exists( StudioAssistant::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with Divi 5 Lab) and jhmg-ai-editor-for-divi-5-pro (with lab_restyle) on this site first.' );
}

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = [];
$check    = static function ( bool $ok, string $label, mixed $detail = null ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || null === $detail ? '' : ' (' . substr( is_string( $detail ) ? $detail : (string) wp_json_encode( $detail ), 0, 500 ) . ')' ) );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

global $wpdb;
$run      = getmypid() . '-' . bin2hex( random_bytes( 3 ) );
$prefix   = 'aied labsite ' . $run . ' ';
$userPre  = 'aied_ls_' . $run . '_';
$location = MenuBuilder::targetLocation();
$orig     = [
    'front_val' => FrontPageKind::read(),
    'menu'      => MenuBuilder::snapshot( $location ),
    'pages'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), // phpcs:ignore
    'briefs'    => StudioTable::exists() ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() ) : 0, // phpcs:ignore
];
$batchIds = [];
$users    = [];
$session  = null;
WP_CLI::log( "Lab site build gate, run {$run}" );

BatchTable::ensureReady( true );
$check( BatchTable::exists() && StudioTable::ensureReady(), 'the batch table and the Lab table exist on the real database' );
$log      = Batches::log();
$undo     = Batches::undo();
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
$build    = $registry->get( BuildSite::NAME );
$restyle  = $registry->get( LabRestyle::NAME );
$check( $build instanceof BuildSite && $restyle instanceof LabRestyle && null === $restyle->unsupported(), 'build_site and lab_restyle are registered and the free plugin supports Lab' );

// ---- helpers ----------------------------------------------------------------------------------------------------------------
$stored = static function ( int $id ): string {
    clean_post_cache( $id );
    $p = get_post( $id );

    return $p ? (string) $p->post_content : '';
};
$sha     = static fn ( string $s ): string => substr( sha1( $s ), 0, 12 ) . '/' . strlen( $s );
$refusal = static function ( callable $fn ): string {
    try {
        $fn();
    } catch ( \InvalidArgumentException $e ) {
        return $e->getMessage();
    }

    return '';
};
$compile = static fn ( array $b ): array => StudioCompiler::compile( $b, static fn ( string $path ): bool => url_to_postid( home_url( $path ) ) > 0, (string) home_url() );
/** The rows that matter of a user's brief (updated_at and plugin_version are bookkeeping). */
$rowOf = static function ( int $owner, int $id ): ?array {
    $r = StudioStore::get( $owner, $id );

    return null === $r ? null : [ 'brief' => $r['brief'], 'brief_hash' => $r['brief_hash'], 'page_id' => $r['page_id'], 'content_hash' => $r['content_hash'] ];
};
$makeUser = static function ( string $k ) use ( $userPre, &$users, $check ): int {
    $login = $userPre . $k;
    $id    = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.invalid', 'role' => 'administrator', 'display_name' => 'aied labsite ' . $k ] );
    $check( ! is_wp_error( $id ), "a throwaway administrator ({$k}) was created", is_wp_error( $id ) ? $id->get_error_message() : null );
    if ( is_wp_error( $id ) ) {
        return 0;
    }
    $users[ $k ] = (int) $id;

    return (int) $id;
};
$keepBatch = static function ( mixed $b ) use ( &$batchIds ): void {
    if ( is_array( $b ) && isset( $b['id'] ) && (int) $b['id'] > 0 ) {
        $batchIds[] = (int) $b['id'];
    }
};

// The words: page 1 carries hostile text (a script tag, a colour token, a kit marker, a dynamic-content variable, a block comment);
// every page has its own title, so every brief has its own hash.
$HOSTILE = 'Tom & Jerry\'s "best" <script>alert(1)</script> {{aied:color:accent}} {"aied":{"kit":"control"}} $variable({"type":"content","value":{"name":"post_title"}})$ <!-- wp:divi/text /--> end';
$briefOf = static function ( int $n ) use ( $prefix, $HOSTILE ): array {
    $b = [
        'version'  => 1,
        'title'    => $prefix . sprintf( 'p%02d', $n ),
        'goal'     => 'book',
        'sections' => [
            [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => 1 === $n ? $HOSTILE : 'Page ' . $n . ': calm rooms & "honest" prices', 'button.0.link' => '#book' ] ],
            [ 'recipe' => 'cta-band', 'slots' => [ 'heading.0' => 'Ready when you are (' . $n . ')', 'button.0.link' => '#book' ] ],
        ],
    ];
    if ( 1 === $n ) {
        $b['language'] = 'editorial'; // only the first brief names the look
        array_splice( $b['sections'], 1, 0, [ [ 'recipe' => 'blurb-grid', 'slots' => [ 'heading.0' => 'Why guests return' ] ] ] );
    }

    return $b;
};
/** A brief as the build fills it (the build's look is editorial + quiet-solid): page 1 unchanged, the others with language and kit. */
$filled = static function ( int $n ) use ( $briefOf ): array {
    $b = $briefOf( $n );
    if ( 1 !== $n ) {
        $b['language'] = 'editorial';
        $b['kit']      = 'quiet-solid';
    }

    return $b;
};
/** Writes the direct compile of $brief onto the twin page through HistoryService::write (the save path lab_restyle uses) and returns it as stored. */
$reference = static function ( int $twin, array $brief ) use ( $compile, $stored ): string {
    $c = $compile( $brief );
    if ( empty( $c['ok'] ) ) {
        return 'compile failed';
    }
    $w = HistoryService::write( $twin, (string) $c['markup'], 'aied_gate_reference' );

    return empty( $w['ok'] ) ? 'write failed' : $stored( $twin );
};

try {
    $A = $makeUser( 'a' );
    $T = $makeUser( 't' );
    $C = $makeUser( 'c' );
    if ( $A <= 0 || $T <= 0 || $C <= 0 ) {
        throw new \RuntimeException( 'the throwaway users could not be made' );
    }

    // ---- 1. ten brief pages, each byte-equal to the same filled brief saved by the real lab_build ------------------------------
    wp_set_current_user( $A );
    $pages   = [];
    $twins   = [];
    $buildId = 0;
    $allEqual = true;
    for ( $n = 1; $n <= 10; $n++ ) {
        $args = [ 'action' => 'add_page', 'brief' => $briefOf( $n ), 'slug' => 'aied-labsite-' . $run . sprintf( '-p%02d', $n ) ] + ( 1 === $n ? [ 'is_front' => true ] : [ 'build_id' => $buildId ] );
        try {
            $r = $build->run( $args );
        } catch ( \InvalidArgumentException $e ) {
            $check( false, "brief page {$n} was created", $e->getMessage() );
            continue;
        }
        if ( 1 === $n ) {
            $buildId = (int) $r['build']['id'];
            $batchIds[] = $buildId;
        }
        $id          = (int) $r['page']['id'];
        $pages[ $n ] = $id;
        $ok          = true === $r['created'] && true === ( $r['lab']['stored'] ?? null ) && is_int( $r['lab']['brief_id'] ?? null ) && 'draft' === get_post_status( $id ) && $prefix . sprintf( 'p%02d', $n ) === get_the_title( $id );
        if ( ! $ok ) {
            $check( false, "brief page {$n}: created as a draft titled by its brief, its brief stored", $r['lab'] ?? $r );
        }

        // The twin: the same filled brief through the REAL free lab_build, as another user (so the cap and the build are untouched).
        wp_set_current_user( $T );
        $tw = StudioAssistant::run( [ 'action' => 'build', 'create' => true, 'brief' => $filled( $n ) ] );
        wp_set_current_user( $A );
        $tid = (int) ( $tw['body']['page']['id'] ?? 0 );
        if ( 200 !== $tw['status'] || $tid <= 0 ) {
            $check( false, "lab_build made the twin of page {$n}", $tw );
            $allEqual = false;
            continue;
        }
        $twins[ $n ] = $tid;
        if ( $stored( $id ) !== $stored( $tid ) ) {
            $allEqual = false;
            $check( false, "brief page {$n} equals the page lab_build saved from the same filled brief, byte for byte", $sha( $stored( $id ) ) . ' vs ' . $sha( $stored( $tid ) ) );
        }
    }
    $check( 10 === count( $pages ) && 10 === count( $twins ), 'ten brief pages were added to ONE build (only the first brief names its language) and ten twins made by the real lab_build' );
    $check( 10 === count( $pages ) && $allEqual, 'every brief page EQUALS the page the real lab_build (create:true) saved from the same filled brief, byte for byte (stored bytes against stored bytes)' );
    $check( 10 === count( $pages ) && array_reduce( $pages, static fn ( bool $c, int $id ): bool => $c && LayoutGate::check( $stored( $id ) )->isValid(), true ), 'every brief page passes the layout gate' );
    $row = $log->find( $buildId );
    $check( null !== $row && 'building' === $row['status'] && 10 === count( (array) $row['items'] ), 'the build is one batch row with ten page_created items', null === $row ? null : [ $row['status'], count( (array) $row['items'] ) ] );

    // ---- 2. LOOK_MISMATCH --------------------------------------------------------------------------------------------------
    $pagesBefore = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ); // phpcs:ignore
    $mis         = $briefOf( 11 );
    $mis['language'] = 'luxe';
    $m = $refusal( static fn () => $build->run( [ 'action' => 'add_page', 'build_id' => $buildId, 'brief' => $mis, 'slug' => 'aied-labsite-' . $run . '-mismatch' ] ) );
    $check( str_starts_with( $m, 'LOOK_MISMATCH' ), 'a brief that names another look is refused with LOOK_MISMATCH', $m );
    $check( $pagesBefore === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ) && 10 === StudioStore::countFor( $A ), 'and nothing was created for it (no page, no brief)' ); // phpcs:ignore

    // ---- 3. ten briefs, linked, all in the build's look ---------------------------------------------------------------------
    $linked  = StudioStore::linked( $A );
    $byPage  = [];
    foreach ( $linked as $l ) {
        $byPage[ (int) $l['page_id'] ] = $l;
    }
    $origRows = [];
    $looksOk  = true;
    foreach ( $pages as $n => $id ) {
        $l = $byPage[ $id ] ?? null;
        $looksOk = $looksOk && null !== $l && 'editorial' === ( $l['brief']['language'] ?? null ) && $l['content_hash'] === hash( 'sha256', $stored( $id ) ) && ( 1 === $n ? ! isset( $l['brief']['kit'] ) : 'quiet-solid' === ( $l['brief']['kit'] ?? null ) );
        if ( null !== $l ) {
            $origRows[ $n ] = [ 'id' => (int) $l['id'], 'row' => $rowOf( $A, (int) $l['id'] ), 'content' => $stored( $id ) ];
        }
    }
    $check( 10 === StudioStore::countFor( $A ) && 10 === count( $linked ) && 10 === count( $origRows ), 'ten briefs are stored for the builder and each is linked to its page', [ StudioStore::countFor( $A ), count( $linked ) ] );
    $check( $looksOk, 'every brief is editorial (page 1 as given, the nine others filled with editorial + quiet-solid) with content_hash = sha256(stored page)' );
    $st = $build->run( [ 'action' => 'status', 'build_id' => $buildId ] );
    $check( [ 'language' => 'editorial', 'kit' => 'quiet-solid' ] === ( $st['build']['look'] ?? null ), 'build status reports the build\'s look editorial + quiet-solid', $st['build']['look'] ?? null );

    // ---- 4. the brief cap: 45 stored briefs, a 10-page build stops at the 6th --------------------------------------------------
    $old = [];
    for ( $i = 1; $i <= 45; $i++ ) {
        $s = StudioStore::save( $C, [ 'version' => 1, 'title' => $prefix . 'cap ' . $i, 'goal' => 'book', 'language' => 'editorial', 'sections' => [ [ 'recipe' => 'cta-band', 'slots' => [] ] ] ] );
        if ( $s['ok'] ) {
            $old[] = (int) $s['id'];
        }
    }
    $check( 45 === count( $old ) && 45 === StudioStore::countFor( $C ), 'a third throwaway administrator holds 45 stored briefs' );
    wp_set_current_user( $C );
    $cBuild  = 0;
    $made    = 0;
    $refused = '';
    for ( $n = 1; $n <= 10 && '' === $refused; $n++ ) {
        $b = $briefOf( $n );
        $b['title'] = $prefix . sprintf( 'c%02d', $n );
        if ( 1 !== $n ) {
            unset( $b['language'] );
        } else {
            $b['sections'][0]['slots']['heading.1'] = 'Cap page one';
        }
        try {
            $r = $build->run( [ 'action' => 'add_page', 'brief' => $b, 'slug' => 'aied-labsite-' . $run . sprintf( '-c%02d', $n ), 'in_menu' => false ] + ( $cBuild > 0 ? [ 'build_id' => $cBuild ] : [] ) );
            $made++;
            if ( 0 === $cBuild ) {
                $cBuild     = (int) $r['build']['id'];
                $batchIds[] = $cBuild;
            }
        } catch ( \InvalidArgumentException $e ) {
            $refused = $e->getMessage() . ' (at page ' . $n . ')';
        }
    }
    $check( 5 === $made && str_starts_with( $refused, 'TOO_MANY_BRIEFS' ) && str_contains( $refused, '(at page 6)' ), 'with 45 briefs the 10-page build is refused at the 6th page with TOO_MANY_BRIEFS', [ 'made' => $made, 'refusal' => $refused ] );
    $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . StudioTable::name() . ' WHERE created_by = %d ORDER BY id', $C ) ) ); // phpcs:ignore
    $check( 50 === StudioStore::countFor( $C ) && [] === array_diff( $old, $ids ), 'the person then holds 50 briefs and the 45 old ids are all still there (nothing pruned)', [ 'count' => StudioStore::countFor( $C ), 'missing' => array_values( array_diff( $old, $ids ) ) ] );
    $cu = $undo->undo( $cBuild );
    $keepBatch( $cu['undo_batch'] ?? null );
    $check( true === $cu['fully_undone'], 'that partial build is undone (its five pages trashed)' );
    wp_set_current_user( $A );

    // ---- 5. hostile words render literally on the front end ----------------------------------------------------------------
    $expires = time() + 600;
    $session = WP_Session_Tokens::get_instance( $A )->create( $expires );
    $cookie  = AUTH_COOKIE . '=' . wp_generate_auth_cookie( $A, $expires, 'auth', $session ) . '; ' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( $A, $expires, 'logged_in', $session );
    $host    = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
    $resp    = wp_remote_get( 'http://wordpress/?page_id=' . ( $pages[1] ?? 0 ) . '&preview=true', [ 'headers' => [ 'Host' => $host, 'Cookie' => $cookie ], 'timeout' => 60, 'redirection' => 0 ] );
    $html    = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
    $code    = is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_response_code( $resp );
    $check( 200 === $code && str_contains( $html, 'et-l--post' ), 'page 1 was fetched over HTTP as its administrator (200, Divi content)', $code );
    $text = (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html ) ), ENT_QUOTES | ENT_HTML5 ) );
    $check( str_contains( $text, $HOSTILE ), 'the hostile words appear as text, exactly as written (script tag, token, marker, variable, block comment)', substr( $text, max( 0, (int) strpos( $text, 'Tom' ) ), 300 ) );
    $check( ! str_contains( $html, '<script>alert(1)' ) && str_contains( $html, '&lt;script&gt;alert(1)' ), 'the script tag is escaped in the HTML, never markup' );
    $check( ! str_contains( $html, '{{aied:' ) && ! str_contains( $html, '"aied":{' ) && ! str_contains( $html, '$variable(' ), 'no aied token, kit marker or dynamic-content variable is left raw in the HTML (they are escaped as text)' );

    // ---- 6. lab_restyle over the drafts to luxe ---------------------------------------------------------------------------
    $ids10 = array_values( $pages );
    $p     = $restyle->run( [ 'page_ids' => $ids10, 'to_language' => 'luxe', 'to_kit' => 'default', 'dry_run' => true ] );
    $check( 10 === $p['would_change'] && 0 === $p['would_skip'] && is_string( $p['token'] ), 'lab_restyle preview over the ten drafts: ten would change, a token', [ $p['would_change'], $p['would_skip'] ] );
    $r1 = $restyle->run( [ 'page_ids' => $ids10, 'to_language' => 'luxe', 'to_kit' => 'default', 'token' => (string) $p['token'] ] );
    $keepBatch( $r1['batch'] ?? null );
    $check( 10 === $r1['restyled'] && 0 === $r1['brief_not_updated'] && [] === $r1['skipped'] && true === ( $r1['batch']['undoable'] ?? null ), 'lab_restyle with the token: ten restyled, every brief updated, one undoable batch', array_intersect_key( $r1, [ 'restyled' => 1, 'skipped' => 1, 'message' => 1 ] ) );
    $luxeRows = [];
    $equal    = true;
    foreach ( $pages as $n => $id ) {
        $bid  = $origRows[ $n ]['id'] ?? 0;
        $exp  = $origRows[ $n ]['row']['brief'] ?? [];
        $exp['language'] = 'luxe';
        unset( $exp['kit'] );
        $ref  = $reference( $twins[ $n ] ?? 0, $exp );
        $now  = $stored( $id );
        $row  = $rowOf( $A, $bid );
        $good = $now === $ref && null !== $row && $row['brief'] === $exp && $row['content_hash'] === hash( 'sha256', $now ) && $now !== ( $origRows[ $n ]['content'] ?? '' );
        if ( ! $good ) {
            $equal = false;
            $check( false, "page {$n}: restyled to luxe equals the direct compile saved through the same path, brief row new", [ 'page' => $sha( $now ), 'ref' => $sha( $ref ), 'language' => $row['brief']['language'] ?? null ] );
        }
        $luxeRows[ $n ] = [ 'row' => $row, 'content' => $now ];
    }
    $check( $equal, 'every restyled draft EQUALS the direct compile of its restyled brief saved through the same save path, byte for byte; each brief holds luxe and the stored content\'s hash' );
    $st = $build->run( [ 'action' => 'status', 'build_id' => $buildId ] );
    $check( [ 'language' => 'luxe', 'kit' => 'gilt-hairline' ] === ( $st['build']['look'] ?? null ), 'build status now reports luxe + gilt-hairline (the look follows the briefs)', $st['build']['look'] ?? null );

    // ---- 7. launch -----------------------------------------------------------------------------------------------------------
    $d = $build->run( [ 'action' => 'launch', 'build_id' => $buildId, 'dry_run' => true ] );
    $check( 10 === $d['would_publish'] && 0 === $d['would_skip'] && is_string( $d['token'] ), 'launch dry run: ten pages would go live, a token' );
    $l = $build->run( [ 'action' => 'launch', 'build_id' => $buildId, 'token' => (string) $d['token'] ] );
    $check( true === $l['changed'] && 10 === count( $l['published'] ) && [] === $l['skipped'], 'launch: ten published, none skipped' );
    $check( 10 === count( array_filter( $ids10, static fn ( int $id ): bool => 'publish' === get_post_status( $id ) ) ) && (int) get_option( 'page_on_front' ) === ( $pages[1] ?? -1 ), 'the ten pages are published and page 1 is the front page' );

    // ---- 8. lab_restyle and published pages -------------------------------------------------------------------------------
    $no = $restyle->run( [ 'page_ids' => $ids10, 'to_language' => 'editorial', 'to_kit' => 'default', 'dry_run' => true ] );
    $check( 0 === $no['would_change'] && 10 === $no['would_skip'] && empty( $no['token'] ) && [ 'published' ] === array_values( array_unique( array_column( $no['pages'], 'reason' ) ) ), 'without include_published all ten launched pages are skipped (published), no token', array_column( $no['pages'], 'reason' ) );
    $yes = $restyle->run( [ 'page_ids' => $ids10, 'to_language' => 'editorial', 'to_kit' => 'default', 'include_published' => true, 'dry_run' => true ] );
    $r2  = $restyle->run( [ 'page_ids' => $ids10, 'to_language' => 'editorial', 'to_kit' => 'default', 'include_published' => true, 'token' => (string) ( $yes['token'] ?? '' ) ] );
    $keepBatch( $r2['batch'] ?? null );
    $check( 10 === ( $yes['would_change'] ?? null ) && 10 === $r2['restyled'] && 0 === $r2['brief_not_updated'], 'with include_published all ten are restyled (preview + token)', array_intersect_key( $r2, [ 'restyled' => 1, 'skipped' => 1, 'message' => 1 ] ) );
    $equal = true;
    foreach ( $pages as $n => $id ) {
        $exp = $luxeRows[ $n ]['row']['brief'] ?? [];
        $exp['language'] = 'editorial';
        unset( $exp['kit'] );
        $ref = $reference( $twins[ $n ] ?? 0, $exp );
        $equal = $equal && $stored( $id ) === $ref && 'publish' === get_post_status( $id ) && ( $rowOf( $A, (int) $origRows[ $n ]['id'] )['brief'] ?? null ) === $exp;
    }
    $check( $equal, 'every restyled published page EQUALS the direct compile saved through the same path, is still published, and its brief holds the new look' );

    // ---- 9. undo: the two restyles, then the build -------------------------------------------------------------------------
    $u2 = $undo->undo( (int) ( $r2['batch']['id'] ?? 0 ) );
    $keepBatch( $u2['undo_batch'] ?? null );
    $back = true;
    foreach ( $pages as $n => $id ) {
        $back = $back && $stored( $id ) === $luxeRows[ $n ]['content'] && $rowOf( $A, (int) $origRows[ $n ]['id'] ) === $luxeRows[ $n ]['row'];
    }
    $check( true === $u2['fully_undone'] && 20 === $u2['restored'] && $back, 'undo_batch of the published restyle: fully undone (20 items), every page and brief row exactly as after the first restyle', [ $u2['fully_undone'], $u2['restored'] ] );
    $u1 = $undo->undo( (int) ( $r1['batch']['id'] ?? 0 ) );
    $keepBatch( $u1['undo_batch'] ?? null );
    $back = true;
    foreach ( $pages as $n => $id ) {
        $back = $back && $stored( $id ) === $origRows[ $n ]['content'] && $rowOf( $A, (int) $origRows[ $n ]['id'] ) === $origRows[ $n ]['row'];
    }
    $check( true === $u1['fully_undone'] && 20 === $u1['restored'] && $back, 'undo_batch of the draft restyle: fully undone, every page byte for byte and every brief row exactly as built', [ $u1['fully_undone'], $u1['restored'] ] );
    $ub = $undo->undo( $buildId );
    $keepBatch( $ub['undo_batch'] ?? null );
    $check( true === $ub['fully_undone'], 'undo_batch of the launched build: fully undone', array_intersect_key( $ub, [ 'fully_undone' => 1, 'restored' => 1 ] ) );
    $check( 10 === count( array_filter( $ids10, static fn ( int $id ): bool => 'trash' === get_post_status( $id ) ) ), 'the ten pages are in the trash' );
    $kept = true;
    foreach ( $pages as $n => $id ) {
        $kept = $kept && $rowOf( $A, (int) $origRows[ $n ]['id'] ) === $origRows[ $n ]['row'];
    }
    $check( 10 === StudioStore::countFor( $A ) && $kept, 'the ten briefs are left as they were (the person\'s words are kept)' );
    $check( FrontPageKind::read() === $orig['front_val'] && MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ) === MenuBuilder::canonical( $orig['menu'] ), 'the front page and the Main Menu are back as before the launch' );
} catch ( \Throwable $ex ) {
    $check( false, 'unexpected error: ' . get_class( $ex ) . ': ' . $ex->getMessage() . ' at ' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
    // ---- cleanup -----------------------------------------------------------------------------------------------------------------
    wp_set_current_user( 1 );
    if ( null !== $session && isset( $users['a'] ) ) {
        WP_Session_Tokens::get_instance( $users['a'] )->destroy( $session );
    }
    FrontPageKind::set( $orig['front_val']['show_on_front'], $orig['front_val']['page_on_front'] );
    MenuBuilder::restore( $orig['menu'] );
    $mine = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_title LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ) ); // phpcs:ignore
    foreach ( $mine as $id ) {
        wp_delete_post( $id, true );
    }
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
    }
    if ( [] !== $users && StudioTable::exists() ) {
        $wpdb->query( 'DELETE FROM ' . StudioTable::name() . ' WHERE created_by IN (' . implode( ',', array_map( 'intval', $users ) ) . ')' ); // phpcs:ignore
    }
    foreach ( $users as $uid ) {
        delete_option( 'aied_pro_buildlock_' . $uid );
        $u = get_userdata( $uid );
        if ( $u && str_starts_with( $u->user_login, $userPre ) ) {
            wp_delete_user( $uid );
        }
    }
}

$check( FrontPageKind::read() === $orig['front_val'], 'cleanup: the front page settings are as before' );
$check( MenuBuilder::canonical( $orig['menu'] ) === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'cleanup: the Main Menu and its location are as before' );
$check( $orig['pages'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), 'cleanup: every throwaway page is deleted' ); // phpcs:ignore
$check( $orig['briefs'] === (StudioTable::exists() ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . StudioTable::name() ) : 0), 'cleanup: every Lab brief row of the throwaway users is deleted (the table holds what it held before)' ); // phpcs:ignore
$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( $userPre ) . '%' ) ), 'cleanup: the throwaway users are deleted' ); // phpcs:ignore
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
$check( 0 === $left, 'cleanup: every batch row this script created is deleted (' . count( array_unique( $batchIds ) ) . ')' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'build_site with Lab briefs verified on the real site: lab_build parity, shared look, cap, hostile words, restyle, launch, include_published, undo.' );
