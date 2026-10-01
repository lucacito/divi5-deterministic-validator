<?php
/**
 * Real-database check for the undo-history table. The unit tests use a SQLite fake; this proves
 * the MySQL/MariaDB side: dbDelta schema, utf8mb4 content, unique key, migration, delete cleanup.
 *
 * Run: make verify-history   (needs `make up` and the plugin active on the dev site)
 * It creates ONE throwaway draft page, exercises everything on it, and deletes it again.
 */

use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\PageHistory;

if ( ! class_exists( HistoryStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$rowCount = static function ( int $pageId ): int {
    global $wpdb;
    $table = HistoryTable::name();
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE page_id = %d", $pageId ) ); // phpcs:ignore
};

// Make sure the table exists (this is the real dbDelta path on a fresh site).
HistoryTable::ensureReady( true );
$check( HistoryTable::exists(), 'table exists after ensureReady' );

$pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied history check', 'post_content' => 'ORIGINAL' ], true );
if ( is_wp_error( $pageId ) ) {
    WP_CLI::error( $pageId->get_error_message() );
}

try {
    // ---- 1. Migration from the 4.0.0 post-meta format -----------------------------------------
    $tricky = "<p class=\"a\">C:\\path \\\\ \\u003Cp\\u003E \n\t \u{1F600} \u{e9}</p>";
    $legacy = [ 'next' => 3, 'items' => [
        [ 'id' => 2, 'saved_at' => '2026-10-01T02:00:00Z', 'tool' => 'update_page_layout', 'actor' => 1, 'bytes' => strlen( $tricky ), 'sha256' => hash( 'sha256', $tricky ), 'label' => null, 'content' => $tricky ],
        [ 'id' => 1, 'saved_at' => '2026-10-01T01:00:00Z', 'tool' => 'update_page_layout', 'actor' => 1, 'bytes' => 9, 'sha256' => hash( 'sha256', 'legacy one' ), 'label' => null, 'content' => 'legacy one' ],
    ] ];
    update_post_meta( $pageId, HistoryStore::LEGACY_META_KEY, wp_slash( wp_json_encode( $legacy ) ) );
    // Deliberately force the stage back so this re-runs the dev site's own legacy (post-meta) migration too.
    update_option( HistoryTable::DB_VERSION_OPTION, HistoryTable::STAGE_TABLE, true );
    for ( $i = 0; $i < 50 && ! HistoryTable::migrationDone(); $i++ ) {
        HistoryTable::ensureReady( true );
    }
    $check( HistoryTable::migrationDone(), 'migration reaches the done stage' );
    $check( '' === get_post_meta( $pageId, HistoryStore::LEGACY_META_KEY, true ), 'legacy meta removed after import' );
    $check( [ 2, 1 ] === array_column( HistoryService::listFor( $pageId ), 'id' ), 'imported ids kept (2, 1)' );
    $check( ( HistoryService::entry( $pageId, 2 )['content'] ?? null ) === $tricky, 'migrated content identical byte-for-byte (backslashes, newline, emoji)' );

    // ---- 2. Writes: retention, ids, content fidelity ------------------------------------------
    $ok = true;
    for ( $i = 1; $i <= 13; $i++ ) {
        $new = "V{$i} \\ \" \u{1F600}";
        $w   = HistoryService::write( $pageId, $new, 'update_page_layout' );
        $ok  = $ok && $w['ok'] && get_post( $pageId )->post_content === $new;
    }
    $check( $ok, '13 writes succeed and the page holds exactly the written content' );
    $ids = array_column( HistoryService::listFor( $pageId ), 'id' );
    $check( $ids === range( 15, 6 ), 'retention keeps the newest 10, ids continue after the imported ones (got ' . implode( ',', $ids ) . ')' );

    $big = str_repeat( "<p>\u{1F600} \\ \" \u{e9} 0123456789</p>\n", 12000 ); // exactly 360 000 bytes of multi-byte text, under the 512 KB per-snapshot cap
    $check( strlen( $big ) > 350000 && strlen( $big ) < PageHistory::MAX_BYTES, 'big fixture is large but under the per-snapshot cap (' . strlen( $big ) . ' bytes)' );
    HistoryService::write( $pageId, $big, 'update_page_layout' );
    $w = HistoryService::write( $pageId, 'after big', 'update_page_layout' );
    $check( true === $w['snapshot']['stored'], 'the big content is snapshotted before being replaced' );
    $check( ( HistoryService::entry( $pageId, (int) $w['snapshot']['version_id'] )['content'] ?? null ) === $big, 'big snapshot round-trips byte-for-byte on the real table' );
    $total = 0;
    foreach ( HistoryService::listFor( $pageId ) as $row ) {
        $total += (int) $row['bytes'];
    }
    $check( $total <= PageHistory::MAX_TOTAL_BYTES, "per-page budget respected ({$total} <= " . PageHistory::MAX_TOTAL_BYTES . ')' );

    // Force the byte-budget trimming: three distinct ~360 KB snapshots (1.08 MB) cannot all fit in 768 KB.
    $big2 = 'B2 ' . $big;
    $big3 = 'B3 ' . $big;
    HistoryService::write( $pageId, $big2, 'update_page_layout' );
    HistoryService::write( $pageId, $big3, 'update_page_layout' );
    $w     = HistoryService::write( $pageId, 'after big3', 'update_page_layout' );
    $list  = HistoryService::listFor( $pageId );
    $total = 0;
    foreach ( $list as $row ) {
        $total += (int) $row['bytes'];
    }
    $check( $total <= PageHistory::MAX_TOTAL_BYTES, "byte budget trims large snapshots ({$total} <= " . PageHistory::MAX_TOTAL_BYTES . ')' );
    $check( count( $list ) < 10, 'trimming dropped older snapshots (' . count( $list ) . ' kept, fewer than 10)' );
    $check( true === $w['snapshot']['stored'] && ( $list[0]['id'] ?? null ) === (int) $w['snapshot']['version_id'] && ( HistoryService::entry( $pageId, (int) $w['snapshot']['version_id'] )['content'] ?? null ) === $big3, 'the newest snapshot survives trimming and holds the content current before the last write' );

    // ---- 3. Restore ---------------------------------------------------------------------------
    $list   = HistoryService::listFor( $pageId );
    $oldest = (int) end( $list )['id'];
    $want   = HistoryService::entry( $pageId, $oldest )['content'];
    $r      = HistoryService::restore( $pageId, $oldest );
    $check( $r['ok'] && get_post( $pageId )->post_content === $want, 'restore of the oldest kept version puts back its exact content' );
    $check( true === $r['snapshot']['stored'], 'the restore snapshots the content it replaces' );

    // ---- 4. PHP clash guard: a racing writer with different content is a clash ---------------
    $newest  = (int) HistoryService::listFor( $pageId )[0]['id'];
    $before  = HistoryService::entry( $pageId, $newest )['content'] ?? null;
    $clash   = [ 'next' => $newest + 1, 'items' => [ [ 'id' => $newest, 'saved_at' => 't', 'tool' => 't', 'actor' => 0, 'bytes' => 5, 'sha256' => hash( 'sha256', 'other' ), 'label' => null, 'content' => 'other' ] ] ];
    $check( false === HistoryStore::save( $pageId, $clash ), 'PHP guard: same (page, version) with different content is refused' );
    $check( null !== $before && ( HistoryService::entry( $pageId, $newest )['content'] ?? null ) === $before, 'PHP guard: the stored snapshot is exactly unchanged' );

    // ---- 4b. The database itself: UNIQUE KEY page_version created by dbDelta ----------------------
    global $wpdb;
    $table = HistoryTable::name();
    $index = [];
    foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) as $row ) { // phpcs:ignore
        if ( 'page_version' === $row['Key_name'] ) {
            $index[ (int) $row['Seq_in_index'] ] = [ 'col' => $row['Column_name'], 'non_unique' => (int) $row['Non_unique'] ];
        }
    }
    ksort( $index );
    $check( [ 'page_id', 'version_id' ] === array_column( $index, 'col' ) && [ 0, 0 ] === array_column( $index, 'non_unique' ), 'index page_version exists, is UNIQUE and covers (page_id, version_id)' );
    $prevSuppress = $wpdb->suppress_errors( true );
    $dup          = $wpdb->insert(
        $table,
        [ 'page_id' => $pageId, 'version_id' => $newest, 'saved_at' => 't', 'tool' => 't', 'actor' => 0, 'bytes' => 5, 'sha256' => hash( 'sha256', 'raw' ), 'label' => null, 'content' => 'raw' ],
        [ '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
    );
    $wpdb->suppress_errors( $prevSuppress );
    $check( false === $dup, 'database: a raw duplicate (page_id, version_id) insert is refused by the UNIQUE KEY' );
    $check( null !== $before && ( HistoryService::entry( $pageId, $newest )['content'] ?? null ) === $before, 'database: the stored snapshot is still exactly unchanged' );

    // ---- 5. Dashboard query ---------------------------------------------------------------------
    $recent = HistoryStore::recent( 50 );
    $mine   = array_values( array_filter( $recent, static fn ( array $row ): bool => $row['page_id'] === $pageId ) );
    $check( 1 === count( $mine ) && $mine[0]['version_id'] === (int) HistoryService::listFor( $pageId )[0]['id'], 'recent() lists the page once, with its newest version' );

    // ---- 6. Trash keeps history, permanent delete removes it ----------------------------------
    $before = $rowCount( $pageId );
    wp_trash_post( $pageId );
    $check( 'trash' === get_post_status( $pageId ), 'the page really is in the trash' );
    $check( $rowCount( $pageId ) === $before && $before > 0, 'trashing a page keeps its history' );
    wp_delete_post( $pageId, true );
    $check( 0 === $rowCount( $pageId ), 'permanently deleting a page removes its history rows (delete_post hook)' );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
} finally {
    // Always leave the dev site clean, even if the run died half way (idempotent after the happy path).
    if ( null !== get_post( $pageId ) ) {
        wp_delete_post( $pageId, true );
    }
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'History table verified on the real database.' );
