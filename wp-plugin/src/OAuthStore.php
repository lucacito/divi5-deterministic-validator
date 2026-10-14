<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code that queries the OAuth table (schema and lifecycle: OAuthTable). It behaves exactly like
 * OAuthStoreMemory, which the unit tests use; make verify-oauth runs the same checks against this one.
 */
final class OAuthStore implements OAuthStoreInterface
{
    public function insert( string $kind, string $hash, array $fields, int $expiresAt, int $now ): bool
    {
        global $wpdb;

        $data = wp_json_encode( is_array( $fields['data'] ?? null ) ? $fields['data'] : [] );
        if ( false === $data ) {
            return false;
        }
        $prev = $wpdb->suppress_errors( true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $done = $wpdb->insert(
            OAuthTable::name(),
            [
                'kind'       => $kind,
                'token_hash' => $hash,
                'client_id'  => (string) ( $fields['client_id'] ?? '' ),
                'user_id'    => (int) ( $fields['user_id'] ?? 0 ),
                'family'     => (string) ( $fields['family'] ?? '' ),
                'data'       => $data,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d' ]
        );
        $wpdb->suppress_errors( $prev );

        return 1 === $done;
    }

    public function find( string $kind, string $hash ): ?array
    {
        global $wpdb;

        $table = OAuthTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT kind, token_hash, client_id, user_id, family, data, expires_at, used_at, created_at FROM {$table} WHERE kind = %s AND token_hash = %s", $kind, $hash ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array( $row ) ? self::toRow( $row ) : null;
    }

    public function consume( string $kind, string $hash, int $now ): bool
    {
        global $wpdb;

        $table = OAuthTable::name();
        // One statement decides the winner: only the caller that flips used_at from NULL sees 1 row changed.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $changed = $wpdb->query(
            $wpdb->prepare( "UPDATE {$table} SET used_at = %d WHERE kind = %s AND token_hash = %s AND used_at IS NULL AND expires_at > %d", $now, $kind, $hash, $now )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return 1 === $changed;
    }

    public function revokeFamily( string $family ): int
    {
        if ( '' === $family ) {
            return 0;
        }

        return $this->delete( 'family = %s', [ $family ] );
    }

    public function revokeClient( string $clientId ): int
    {
        return '' === $clientId ? 0 : $this->delete( 'client_id = %s', [ $clientId ] );
    }

    public function revokeUser( int $userId ): int
    {
        return $userId <= 0 ? 0 : $this->delete( 'kind <> %s AND user_id = %d', [ self::CLIENT, $userId ] );
    }

    public function revokeAll(): void
    {
        $this->delete( '1 = %d', [ 1 ] );
    }

    public function purgeExpired( int $now ): void
    {
        $this->delete( 'expires_at <= %d', [ $now ] );
    }

    public function countLive( string $kind, int $now ): int
    {
        global $wpdb;

        $table = OAuthTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE kind = %s AND expires_at > %d", $kind, $now ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $count;
    }

    public function clientsOldestFirst( int $limit ): array
    {
        global $wpdb;

        $table = OAuthTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT kind, token_hash, client_id, user_id, family, data, expires_at, used_at, created_at FROM {$table} WHERE kind = %s ORDER BY created_at ASC, id ASC LIMIT %d", self::CLIENT, $limit ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array_map( [ self::class, 'toRow' ], is_array( $rows ) ? $rows : [] );
    }

    public function clientIdsWithLiveGrants( int $now ): array
    {
        global $wpdb;

        $table = OAuthTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT client_id FROM {$table} WHERE kind <> %s AND expires_at > %d AND client_id <> ''", self::CLIENT, $now ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array_values( array_map( 'strval', is_array( $ids ) ? $ids : [] ) );
    }

    public function liveGrants( int $now, int $limit ): array
    {
        global $wpdb;

        $table = OAuthTable::name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT family, client_id, user_id, created_at FROM {$table} WHERE kind = %s AND used_at IS NULL AND expires_at > %d ORDER BY created_at DESC, id DESC LIMIT %d", self::REFRESH, $now, $limit ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            $out[] = [ 'family' => (string) $r['family'], 'client_id' => (string) $r['client_id'], 'user_id' => (int) $r['user_id'], 'created_at' => (int) $r['created_at'] ];
        }

        return $out;
    }

    /**
     * @param list<scalar> $args
     */
    private function delete( string $where, array $args ): int
    {
        global $wpdb;

        $table = OAuthTable::name();
        // $where is one of this class's own fixed fragments, never input.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where}", ...$args ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_int( $n ) ? $n : 0;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function toRow( array $r ): array
    {
        $data = json_decode( (string) $r['data'], true );

        return [
            'kind'       => (string) $r['kind'],
            'hash'       => (string) $r['token_hash'],
            'client_id'  => (string) $r['client_id'],
            'user_id'    => (int) $r['user_id'],
            'family'     => (string) $r['family'],
            'data'       => is_array( $data ) ? $data : [],
            'expires_at' => (int) $r['expires_at'],
            'used_at'    => null === $r['used_at'] ? null : (int) $r['used_at'],
            'created_at' => (int) $r['created_at'],
        ];
    }
}
