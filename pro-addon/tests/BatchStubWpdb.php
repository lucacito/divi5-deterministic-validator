<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

/**
 * A recording stand-in for the few $wpdb methods the batch code uses. It proves the calls the code makes;
 * the real SQL is proven on a real database by scripts/verify-pro-settings.php.
 */
final class BatchStubWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    /** @var list<array<int,mixed>> */
    public array $calls = [];
    /** @var array<int,array<string,string>> */
    public array $rows = [];
    public bool $failInsert = false;
    /** What `SELECT COUNT(*)` returns: null = the table does not exist. */
    public ?string $count = '0';
    /** @var list<string> what get_col returns */
    public array $col = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function prepare( string $query, mixed ...$args ): string
    {
        return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
    }

    public function esc_like( string $text ): string
    {
        return addcslashes( $text, '_%\\\\' );
    }

    public function suppress_errors( bool $on = true ): bool
    {
        return false;
    }

    public function get_var( string $query ): ?string
    {
        $this->calls[] = [ 'get_var', $query ];

        return $this->count;
    }

    public function insert( string $table, array $data, array $formats = [] ): int|false
    {
        $this->calls[] = [ 'insert', $table, $data ];
        if ( $this->failInsert ) {
            return false;
        }
        $this->insert_id          = count( $this->rows ) + 1;
        $this->rows[ $this->insert_id ] = [ 'id' => (string) $this->insert_id ] + array_map( 'strval', $data );

        return 1;
    }

    public function get_row( string $query, mixed $output = null ): ?array
    {
        $this->calls[] = [ 'get_row', $query ];
        if ( str_contains( $query, "status = 'building'" ) ) {
            preg_match( '/user_id = (\d+)/', $query, $u );
            foreach ( array_reverse( $this->rows, true ) as $row ) {
                if ( 'building' === $row['status'] && (int) $row['user_id'] === (int) ( $u[1] ?? 0 ) ) {
                    return $row;
                }
            }

            return null;
        }
        preg_match( '/WHERE id = (\d+)/', $query, $m );

        return $this->rows[ (int) ( $m[1] ?? 0 ) ] ?? null;
    }

    /** @return list<array<string,string>> */
    public function get_results( string $query, mixed $output = null ): array
    {
        $this->calls[] = [ 'get_results', $query ];

        return array_reverse( array_values( $this->rows ) );
    }

    public function update( string $table, array $data, array $where, array $formats = [], array $whereFormats = [] ): int|false
    {
        $this->calls[] = [ 'update', $table, $data, $where ];
        $id            = (int) $where['id'];
        if ( ! isset( $this->rows[ $id ] ) ) {
            return false;
        }
        if ( isset( $where['status'] ) && $this->rows[ $id ]['status'] !== (string) $where['status'] ) {
            return 0;
        }
        foreach ( $data as $k => $v ) {
            $this->rows[ $id ][ $k ] = (string) $v;
        }

        return 1;
    }

    /** @return list<string> */
    public function get_col( string $query ): array
    {
        $this->calls[] = [ 'get_col', $query ];

        return $this->col;
    }

    public function query( string $query ): int
    {
        $this->calls[] = [ 'query', $query ];

        return 1;
    }
}
