<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code that queries the history table (see HistoryTable for the schema).
 * A history is the array PageHistory works on: ['next' => int, 'items' => list<item>], newest first.
 */
final class HistoryStore
{
    /** Where 4.0.0 kept the history; read only to migrate it (HistoryMigration) and removed on uninstall. */
    public const LEGACY_META_KEY = '_aied_history';

    /**
     * `failed` is present (true) only when the read itself failed: that is not an empty history,
     * and the caller must not record or save anything based on it.
     *
     * @return array{next:int, items:list<array<string,mixed>>, failed?:true}
     */
    public static function load( int $pageId ): array
    {
        if ( ! HistoryTable::hasTable() ) {
            return PageHistory::empty();
        }
        self::importLegacy( $pageId );
        $rows = self::fetch( $pageId, null, true );
        if ( null === $rows ) {
            return PageHistory::empty() + [ 'failed' => true ];
        }

        // normalize() derives `next` as max(id) + 1: ids only grow and the newest row is never trimmed.
        $history = PageHistory::normalize( [ 'next' => 1, 'items' => array_map( [ self::class, 'toItem' ], $rows ) ] );
        if ( ! HistoryTable::migrationDone() ) {
            // Old history that could not be imported yet still owns its ids: never hand one out again.
            $history['next'] = max( $history['next'], HistoryMigration::legacyMaxId( $pageId ) + 1 );
        }

        return $history;
    }

    /**
     * Makes the stored rows for the page match $history: inserts rows that are not stored yet,
     * then deletes the rows PageHistory trimmed. Returns false (deleting nothing) when an insert
     * fails or clashes with a stored row holding different content.
     *
     * @param array{next:int, items:list<array<string,mixed>>} $history
     */
    public static function save( int $pageId, array $history ): bool
    {
        global $wpdb;

        if ( ! HistoryTable::hasTable() ) {
            return false;
        }
        $history = PageHistory::normalize( $history );
        if ( ! self::insertMissing( $pageId, $history['items'] ) ) {
            return false;
        }

        $keep = array_map( static fn ( array $item ): int => (int) $item['id'], $history['items'] );
        if ( [] !== $keep ) {
            // Trimming only ever removes the oldest versions and the newest is always kept, so one
            // range delete does it, and a history that looks shorter than it is can never delete much.
            // A failed trim leaves extra old rows; the next save removes them.
            $table = HistoryTable::name();
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE page_id = %d AND version_id < %d", $pageId, min( $keep ) ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        return true;
    }

    /**
     * Inserts the items whose version id is not stored yet. A stored id with a different sha256
     * means another writer took that id with other content: reported as a failure, never overwritten.
     *
     * @param list<array<string,mixed>> $items
     */
    public static function insertMissing( int $pageId, array $items ): bool
    {
        $stored = self::storedHashes( $pageId );
        foreach ( $items as $item ) {
            $id  = (int) $item['id'];
            $sha = (string) ( $item['sha256'] ?? hash( 'sha256', (string) $item['content'] ) );
            if ( isset( $stored[ $id ] ) ) {
                if ( $stored[ $id ] !== $sha ) {
                    return false;
                }
                continue;
            }
            // A failed insert may be a race with another request storing the very same snapshot.
            if ( ! self::insertRow( $pageId, $item, $sha ) && self::storedHash( $pageId, $id ) !== $sha ) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{id:int, saved_at:string, tool:string, actor:int, bytes:int, label:?string}> */
    public static function summaries( int $pageId ): array
    {
        if ( ! HistoryTable::hasTable() ) {
            return [];
        }
        self::importLegacy( $pageId );
        $out = [];
        foreach ( self::fetch( $pageId, null, false ) ?? [] as $row ) {
            $item  = self::toItem( $row );
            $out[] = [
                'id'       => $item['id'],
                'saved_at' => $item['saved_at'],
                'tool'     => $item['tool'],
                'actor'    => $item['actor'],
                'bytes'    => $item['bytes'],
                'label'    => $item['label'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function entry( int $pageId, int $versionId ): ?array
    {
        if ( ! HistoryTable::hasTable() ) {
            return null;
        }
        self::importLegacy( $pageId );
        $rows = self::fetch( $pageId, $versionId, true );

        return null === $rows || [] === $rows ? null : self::toItem( $rows[0] );
    }

    /**
     * The newest snapshot of every page, newest first, without any snapshot content.
     *
     * @return list<array{page_id:int, version_id:int, saved_at:string, tool:string}>
     */
    public static function recent( int $limit ): array
    {
        global $wpdb;

        if ( ! HistoryTable::hasTable() ) {
            return [];
        }
        $table = HistoryTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.page_id, h.version_id, h.saved_at, h.tool
                 FROM {$table} h
                 INNER JOIN ( SELECT page_id, MAX(version_id) AS newest FROM {$table} GROUP BY page_id ) n
                         ON n.page_id = h.page_id AND n.newest = h.version_id
                 ORDER BY h.saved_at DESC, h.page_id DESC
                 LIMIT %d",
                max( 0, $limit )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = [
                'page_id'    => (int) $row['page_id'],
                'version_id' => (int) $row['version_id'],
                'saved_at'   => (string) $row['saved_at'],
                'tool'       => (string) $row['tool'],
            ];
        }

        return $out;
    }

    /** `delete_post` action: permanently deleting a page removes its history (trashing does not). */
    public static function deleteForPage( int $pageId ): void
    {
        global $wpdb;

        if ( ! HistoryTable::hasTable() ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( HistoryTable::name(), [ 'page_id' => $pageId ], [ '%d' ] );
    }

    /** Uninstall: the table, its option, and any history still sitting in post meta. */
    public static function deleteAll(): void
    {
        HistoryTable::drop();
        delete_post_meta_by_key( self::LEGACY_META_KEY );
    }

    /** Until the batch migration has finished, a page's old post-meta history is imported when first read. */
    private static function importLegacy( int $pageId ): void
    {
        if ( ! HistoryTable::migrationDone() ) {
            HistoryMigration::importPage( $pageId );
        }
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function insertRow( int $pageId, array $item, string $sha ): bool
    {
        global $wpdb;

        $content = (string) $item['content'];
        // An expected UNIQUE clash (two requests racing) must not print a database error into an API response.
        $prev = $wpdb->suppress_errors( true );
        // No wp_slash here: $wpdb->insert escapes the values itself, slashing would double the backslashes.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = $wpdb->insert(
            HistoryTable::name(),
            [
                'page_id'    => $pageId,
                'version_id' => (int) $item['id'],
                'saved_at'   => (string) ( $item['saved_at'] ?? '' ),
                'tool'       => (string) ( $item['tool'] ?? '' ),
                'actor'      => (int) ( $item['actor'] ?? 0 ),
                'bytes'      => (int) ( $item['bytes'] ?? strlen( $content ) ),
                'sha256'     => $sha,
                'label'      => isset( $item['label'] ) ? (string) $item['label'] : null,
                'content'    => $content,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );
        $wpdb->suppress_errors( $prev );

        return false !== $ok;
    }

    /** The stored sha256 of one version, or null when it is not stored (or cannot be read). */
    private static function storedHash( int $pageId, int $versionId ): ?string
    {
        global $wpdb;

        $table = HistoryTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sha = $wpdb->get_var( $wpdb->prepare( "SELECT sha256 FROM {$table} WHERE page_id = %d AND version_id = %d", $pageId, $versionId ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_string( $sha ) ? $sha : null;
    }

    /** @return array<int,string> stored version id => sha256 */
    private static function storedHashes( int $pageId ): array
    {
        global $wpdb;

        $table = HistoryTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT version_id, sha256 FROM {$table} WHERE page_id = %d", $pageId ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row['version_id'] ] = (string) $row['sha256'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>>|null rows newest first (`content` only when asked for); null when the read failed */
    private static function fetch( int $pageId, ?int $versionId, bool $withContent ): ?array
    {
        global $wpdb;

        $table = HistoryTable::name();
        $cols  = 'version_id, saved_at, tool, actor, bytes, sha256, label' . ( $withContent ? ', content' : '' );
        $sql   = "SELECT {$cols} FROM {$table} WHERE page_id = %d";
        $args  = [ $pageId ];
        if ( null !== $versionId ) {
            $sql   .= ' AND version_id = %d';
            $args[] = $versionId;
        }
        $sql .= ' ORDER BY version_id DESC';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // wpdb returns an empty array both for "no rows" and for a failed query; last_error tells them apart.
        if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
            return null;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function toItem( array $row ): array
    {
        $item = [
            'id'       => (int) $row['version_id'],
            'saved_at' => (string) $row['saved_at'],
            'tool'     => (string) $row['tool'],
            'actor'    => (int) $row['actor'],
            'bytes'    => (int) $row['bytes'],
            'sha256'   => (string) $row['sha256'],
            'label'    => null === $row['label'] ? null : (string) $row['label'],
        ];
        if ( array_key_exists( 'content', $row ) ) {
            $item['content'] = (string) $row['content'];
        }

        return $item;
    }
}
