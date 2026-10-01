<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moves 4.0.0's post-meta history (`_aied_history`, one JSON blob per page) into the history table.
 * Safe to run any number of times: rows that are already stored are skipped, and a page's meta is
 * deleted only after every one of its snapshots is stored.
 */
final class HistoryMigration
{
    /**
     * Imports one page. True means nothing is left to import for it (it was imported, held no
     * valid snapshot, or had no legacy meta); false means rows could not be stored and the meta
     * stays for a retry.
     */
    public static function importPage( int $pageId ): bool
    {
        $raw = get_post_meta( $pageId, HistoryStore::LEGACY_META_KEY, true );
        if ( ! is_string( $raw ) || '' === $raw ) {
            // Absent reads as '' too. Removing an empty or unusable value is harmless and keeps it
            // from being listed again on every batch.
            if ( false !== $raw ) {
                delete_post_meta( $pageId, HistoryStore::LEGACY_META_KEY );
            }

            return true;
        }

        $decoded = json_decode( $raw, true );
        $items   = PageHistory::normalize( $decoded )['items'];
        // A value with no valid snapshot is junk (4.0.0 treated it as an empty history and the next
        // save overwrote it). It must go, or the migration could never be declared finished.
        if ( [] !== $items && ! HistoryStore::insertMissing( $pageId, $items ) ) {
            return false;
        }
        delete_post_meta( $pageId, HistoryStore::LEGACY_META_KEY );

        return true;
    }

    /** The highest snapshot id still held in the page's old post meta (0 when there is none). */
    public static function legacyMaxId( int $pageId ): int
    {
        $raw = get_post_meta( $pageId, HistoryStore::LEGACY_META_KEY, true );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return 0;
        }
        $max = 0;
        foreach ( PageHistory::normalize( json_decode( $raw, true ) )['items'] as $item ) {
            $max = max( $max, (int) $item['id'] );
        }

        return $max;
    }

    /**
     * Imports up to $limit pages that still hold legacy meta.
     *
     * @return bool true when no legacy meta is left (a short batch finishes the migration)
     */
    public static function run( int $limit ): bool
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id LIMIT %d",
                HistoryStore::LEGACY_META_KEY,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $ids     = is_array( $ids ) ? $ids : [];
        $allDone = true;
        foreach ( $ids as $id ) {
            if ( ! self::importPage( (int) $id ) ) {
                $allDone = false;
            }
        }

        if ( ! $allDone ) {
            // A page that cannot be stored would be retried on every request: wait before the next batch.
            set_transient( HistoryTable::BACKOFF_TRANSIENT, 1, HistoryTable::BACKOFF_SECONDS );
        }

        return $allDone && count( $ids ) < $limit;
    }
}
