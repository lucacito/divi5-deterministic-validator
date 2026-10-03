<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The only code that queries the batch table (see BatchTable for the schema). */
final class WpdbBatchRepository implements BatchRepository
{
    public function insert( array $row ): ?int
    {
        global $wpdb;

        $items = wp_json_encode( $row['items'] ?? [] );
        if ( false === $items ) {
            return null;
        }
        $ok = $wpdb->insert(
            BatchTable::name(),
            [
                'user_id'    => (int) $row['user_id'],
                'tool'       => (string) $row['tool'],
                'created_at' => (string) $row['created_at'],
                'summary'    => (string) $row['summary'],
                'status'     => (string) $row['status'],
                'items'      => $items,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        return false === $ok ? null : (int) $wpdb->insert_id;
    }

    public function find( int $id ): ?array
    {
        global $wpdb;

        $table = BatchTable::name();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

        return is_array( $row ) ? self::hydrate( $row ) : null;
    }

    public function recent( int $limit ): array
    {
        global $wpdb;

        $table = BatchTable::name();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 0, $limit ) ), ARRAY_A );

        return array_map( [ self::class, 'hydrate' ], is_array( $rows ) ? $rows : [] );
    }

    public function update( int $id, string $status, array $items ): bool
    {
        global $wpdb;

        $json = wp_json_encode( $items );
        if ( false === $json ) {
            return false;
        }

        return false !== $wpdb->update( BatchTable::name(), [ 'status' => $status, 'items' => $json ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
    }

    public function trim( int $keep ): void
    {
        global $wpdb;

        $table = BatchTable::name();
        $ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1000 OFFSET %d", max( 0, $keep ) ) );
        if ( ! is_array( $ids ) || [] === $ids ) {
            return;
        }
        $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate( array $row ): array
    {
        $items = json_decode( (string) ( $row['items'] ?? '' ), true );

        return [
            'id'         => (int) $row['id'],
            'user_id'    => (int) $row['user_id'],
            'tool'       => (string) $row['tool'],
            'created_at' => (string) $row['created_at'],
            'summary'    => (string) $row['summary'],
            'status'     => (string) $row['status'],
            'items'      => is_array( $items ) ? array_values( $items ) : [],
        ];
    }
}
