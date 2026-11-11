<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code that queries the Studio table (see StudioTable for the schema): saved briefs.
 *
 * A brief belongs to the user who created it. Another user's brief behaves exactly like a missing one
 * (NOT_FOUND), so an id reveals nothing. Brief contents are never logged or echoed here. A brief is stored
 * as given (StudioBrief::check validated it); this class only encodes and decodes it.
 */
final class StudioStore
{
    public const MAX_BYTES = 204800; // 200 KB of stored JSON
    public const MAX_PER_USER = 50;

    public const NOT_FOUND    = 'NOT_FOUND';
    public const TOO_LARGE    = 'TOO_LARGE';
    public const STORE_FAILED = 'STORE_FAILED';
    public const CONFLICT     = 'CONFLICT';

    /**
     * Saves a brief. With an id and $expectedHash the update is a compare-and-set: it only happens while the stored
     * brief_hash is still $expectedHash, otherwise the answer is CONFLICT and nothing changes (unless the stored brief
     * already equals the one being written, which is a success). $expectedHash without an id is ignored.
     *
     * @return array{ok:bool, id:int, code:?string}
     */
    public static function save( int $user, array $brief, ?int $id = null, ?string $expectedHash = null ): array
    {
        global $wpdb;

        $json = wp_json_encode( $brief );
        if ( ! is_string( $json ) ) {
            return self::fail( self::STORE_FAILED );
        }
        if ( strlen( $json ) > self::MAX_BYTES ) {
            return self::fail( self::TOO_LARGE );
        }
        if ( $user <= 0 || ! StudioTable::ensureReady() ) {
            return self::fail( self::STORE_FAILED );
        }
        $table = StudioTable::name();
        $now   = gmdate( 'Y-m-d H:i:s' );
        $hash  = hash( 'sha256', $json );

        if ( null !== $id ) {
            if ( null === self::row( $user, $id ) ) {
                return self::fail( self::NOT_FOUND );
            }
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( null !== $expectedHash ) {
                $ok = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table} SET brief = %s, brief_hash = %s, plugin_version = %s, updated_at = %s WHERE id = %d AND created_by = %d AND brief_hash = %s",
                    $json,
                    $hash,
                    self::version(),
                    $now,
                    $id,
                    $user,
                    $expectedHash
                ) );
                if ( false !== $ok && 0 === (int) $ok ) {
                    // No row changed: either someone else saved first (a conflict) or the stored brief already is this one.
                    $stored = self::row( $user, $id );
                    if ( null === $stored ) {
                        return self::fail( self::NOT_FOUND );
                    }
                    if ( ! hash_equals( (string) ( $stored['brief_hash'] ?? '' ), $hash ) ) {
                        return self::fail( self::CONFLICT );
                    }
                }
            } else {
                $ok = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table} SET brief = %s, brief_hash = %s, plugin_version = %s, updated_at = %s WHERE id = %d AND created_by = %d",
                    $json,
                    $hash,
                    self::version(),
                    $now,
                    $id,
                    $user
                ) );
            }
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

            return false === $ok ? self::fail( self::STORE_FAILED ) : [ 'ok' => true, 'id' => $id, 'code' => null ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = $wpdb->insert(
            $table,
            [
                'created_by'     => $user,
                'brief'          => $json,
                'brief_hash'     => $hash,
                'plugin_version' => self::version(),
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
        if ( false === $ok ) {
            return self::fail( self::STORE_FAILED );
        }
        $newId = (int) $wpdb->insert_id;
        self::prune( $user, $newId );

        return [ 'ok' => true, 'id' => $newId, 'code' => null ];
    }

    /** @return array{id:int, brief:array<mixed>, page_id:?int, content_hash:?string, brief_hash:string, plugin_version:string, updated_at:string}|null */
    public static function get( int $user, int $id ): ?array
    {
        return self::shape( self::row( $user, $id ) );
    }

    /** Records which page this brief built and the hash of the content that was saved there. */
    public static function link( int $user, int $id, int $pageId, string $contentHash ): bool
    {
        global $wpdb;

        if ( $pageId <= 0 || 1 !== preg_match( '/^[0-9a-f]{64}$/', $contentHash ) || null === self::row( $user, $id ) ) {
            return false;
        }
        $table = StudioTable::name();
        // One brief per page per user: any other brief of this user that pointed at the page lets go of it.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET page_id = NULL, content_hash = NULL WHERE created_by = %d AND page_id = %d AND id <> %d",
            $user,
            $pageId,
            $id
        ) );
        $ok = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET page_id = %d, content_hash = %s, updated_at = %s WHERE id = %d AND created_by = %d",
            $pageId,
            $contentHash,
            gmdate( 'Y-m-d H:i:s' ),
            $id,
            $user
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return false !== $ok;
    }

    /**
     * The user's brief with this brief hash that was linked to a page within the last $seconds (newest first), or null.
     * Lets a second identical "create" (a double click) find the page the first one made instead of making another.
     */
    public static function recentlyLinked( int $user, string $briefHash, int $seconds ): ?array
    {
        global $wpdb;

        if ( $user <= 0 || 1 !== preg_match( '/^[0-9a-f]{64}$/', $briefHash ) || ! StudioTable::ensureReady() ) {
            return null;
        }
        $table = StudioTable::name();
        $since = gmdate( 'Y-m-d H:i:s', time() - max( 0, $seconds ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE created_by = %d AND brief_hash = %s AND page_id IS NOT NULL AND updated_at >= %s ORDER BY updated_at DESC, id DESC LIMIT 1",
                $user,
                $briefHash,
                $since
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return self::shape( is_array( $rows ) && isset( $rows[0] ) ? $rows[0] : null );
    }

    /** How many briefs the user holds (the store keeps at most MAX_PER_USER, so this is what a new brief would push out). */
    public static function countFor( int $user ): int
    {
        global $wpdb;

        if ( $user <= 0 || ! StudioTable::ensureReady() ) {
            return 0;
        }
        $table = StudioTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_by = %d", $user ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return max( 0, (int) $count );
    }

    /** The brief_hash a brief is stored with (sha256 of its JSON), or null when it cannot be encoded. */
    public static function hashOf( array $brief ): ?string
    {
        $json = wp_json_encode( $brief );

        return is_string( $json ) ? hash( 'sha256', $json ) : null;
    }

    /** The user's brief that built this page, or null. */
    public static function forPage( int $user, int $pageId ): ?array
    {
        global $wpdb;

        if ( $user <= 0 || $pageId <= 0 || ! StudioTable::ensureReady() ) {
            return null;
        }
        $table = StudioTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE created_by = %d AND page_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1", $user, $pageId ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return self::shape( is_array( $rows ) && isset( $rows[0] ) ? $rows[0] : null );
    }

    /**
     * The user's briefs that built a page, newest first (read-only, for the Lab screen and tools).
     *
     * @return list<array{id:int, brief:array, page_id:int, content_hash:?string, brief_hash:string, plugin_version:string, updated_at:string}>
     */
    public static function linked( int $user, int $limit = self::MAX_PER_USER ): array
    {
        global $wpdb;

        $limit = max( 0, min( $limit, self::MAX_PER_USER ) );
        if ( $user <= 0 || 0 === $limit || ! StudioTable::ensureReady() ) {
            return [];
        }
        $table = StudioTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE created_by = %d AND page_id IS NOT NULL ORDER BY updated_at DESC, id DESC LIMIT %d", $user, $limit ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $shaped = self::shape( $row );
            if ( null !== $shaped && null !== $shaped['page_id'] ) {
                $out[] = $shaped;
            }
        }

        return $out;
    }

    public static function delete( int $user, int $id ): bool
    {
        global $wpdb;

        if ( null === self::row( $user, $id ) ) {
            return false;
        }
        $table = StudioTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ok = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND created_by = %d", $id, $user ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return false !== $ok;
    }

    /** Removes every stored brief (the table itself is dropped on uninstall by StudioTable::drop()). */
    public static function deleteAll(): void
    {
        global $wpdb;

        $table = StudioTable::name();
        $prev  = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "DELETE FROM {$table}" );
        $wpdb->suppress_errors( $prev );
    }

    /**
     * Keeps at most MAX_PER_USER briefs for the user: the oldest unlinked go first, a brief linked to a page
     * only when nothing unlinked is left. The brief just saved is never removed.
     */
    private static function prune( int $user, int $keepId ): void
    {
        global $wpdb;

        $table = StudioTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, page_id FROM {$table} WHERE created_by = %d ORDER BY updated_at ASC, id ASC", $user ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows   = is_array( $rows ) ? $rows : [];
        $excess = count( $rows ) - self::MAX_PER_USER;
        if ( $excess <= 0 ) {
            return;
        }
        $unlinked = [];
        $linked   = [];
        foreach ( $rows as $row ) {
            $rowId = (int) $row['id'];
            if ( $rowId === $keepId ) {
                continue;
            }
            if ( isset( $row['page_id'] ) && '' !== (string) $row['page_id'] ) {
                $linked[] = $rowId;
            } else {
                $unlinked[] = $rowId;
            }
        }
        foreach ( array_slice( array_merge( $unlinked, $linked ), 0, $excess ) as $victim ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND created_by = %d", $victim, $user ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
    }

    /** @return array<string,mixed>|null the raw row, only when it belongs to $user */
    private static function row( int $user, int $id ): ?array
    {
        global $wpdb;

        if ( $user <= 0 || $id <= 0 || ! StudioTable::ensureReady() ) {
            return null;
        }
        $table = StudioTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND created_by = %d", $id, $user ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array( $rows ) && isset( $rows[0] ) ? $rows[0] : null;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array{id:int, brief:array<mixed>, page_id:?int, content_hash:?string, brief_hash:string, plugin_version:string, updated_at:string}|null
     */
    private static function shape( ?array $row ): ?array
    {
        if ( null === $row ) {
            return null;
        }
        $brief = json_decode( (string) ( $row['brief'] ?? '' ), true );
        if ( ! is_array( $brief ) ) {
            return null; // a corrupt row reads as missing
        }

        return [
            'id'             => (int) $row['id'],
            'brief'          => $brief,
            'page_id'        => isset( $row['page_id'] ) && '' !== (string) $row['page_id'] ? (int) $row['page_id'] : null,
            'content_hash'   => isset( $row['content_hash'] ) && '' !== (string) $row['content_hash'] ? (string) $row['content_hash'] : null,
            'brief_hash'     => (string) ( $row['brief_hash'] ?? '' ),
            'plugin_version' => (string) ( $row['plugin_version'] ?? '' ),
            'updated_at'     => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    /** @return array{ok:bool, id:int, code:?string} */
    private static function fail( string $code ): array
    {
        return [ 'ok' => false, 'id' => 0, 'code' => $code ];
    }

    private static function version(): string
    {
        return defined( 'AI_EDITOR_DIVI5_VERSION' ) ? substr( (string) AI_EDITOR_DIVI5_VERSION, 0, 20 ) : '';
    }
}
