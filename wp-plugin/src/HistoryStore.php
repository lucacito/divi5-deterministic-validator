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

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function load( int $pageId ): array
    {
        if ( ! HistoryTable::hasTable() ) {
            return PageHistory::empty();
        }
        $items = array_map( [ self::class, 'toItem' ], self::fetch( $pageId, null, true ) );

        // normalize() derives `next` as max(id) + 1: ids only grow and the newest row is never trimmed.
        return PageHistory::normalize( [ 'next' => 1, 'items' => $items ] );
    }

    /**
     * Makes the stored rows for the page match $history: inserts rows that are not stored yet,
     * then deletes rows that PageHistory trimmed. Returns false (deleting nothing) when an insert
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
        foreach ( array_keys( self::storedHashes( $pageId ) ) as $stored ) {
            if ( ! in_array( $stored, $keep, true ) ) {
                // A failed trim leaves an extra old row; the next save recomputes and removes it.
                $wpdb->delete( HistoryTable::name(), [ 'page_id' => $pageId, 'version_id' => $stored ], [ '%d', '%d' ] );
            }
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
            $id   = (int) $item['id'];
            $sha  = (string) ( $item['sha256'] ?? hash( 'sha256', (string) $item['content'] ) );
            if ( isset( $stored[ $id ] ) ) {
                if ( $stored[ $id ] !== $sha ) {
                    return false;
                }
                continue;
            }
            if ( ! self::insertRow( $pageId, $item, $sha ) ) {
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
        $out = [];
        foreach ( self::fetch( $pageId, null, false ) as $row ) {
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
        $rows = self::fetch( $pageId, $versionId, true );

        return [] === $rows ? null : self::toItem( $rows[0] );
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
        $wpdb->delete( HistoryTable::name(), [ 'page_id' => $pageId ], [ '%d' ] );
    }

    /** Uninstall: the table, its option, and any history still sitting in post meta. */
    public static function deleteAll(): void
    {
        HistoryTable::drop();
        delete_post_meta_by_key( self::LEGACY_META_KEY );
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function insertRow( int $pageId, array $item, string $sha ): bool
    {
        global $wpdb;

        $content = (string) $item['content'];
        // No wp_slash here: $wpdb->insert escapes the values itself, slashing would double the backslashes.
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

        return false !== $ok;
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

    /** @return list<array<string,mixed>> rows newest first; `content` is selected only when asked for */
    private static function fetch( int $pageId, ?int $versionId, bool $withContent ): array
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

        return is_array( $rows ) ? $rows : [];
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
