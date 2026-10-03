<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchRepository;

require_once __DIR__ . '/../src/Batch/BatchRepository.php';

/** A repository that lives in an array, with switches to simulate a failing store. */
final class InMemoryBatchRepository implements BatchRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public bool $failInsert = false;
    private int $next = 1;

    public function insert( array $row ): ?int
    {
        if ( $this->failInsert ) {
            return null;
        }
        $id                = $this->next++;
        $this->rows[ $id ] = [ 'id' => $id ] + $row;

        return $id;
    }

    public function find( int $id ): ?array
    {
        return $this->rows[ $id ] ?? null;
    }

    public function recent( int $limit ): array
    {
        return array_slice( array_reverse( array_values( $this->rows ) ), 0, $limit );
    }

    public function update( int $id, string $status, array $items ): bool
    {
        if ( ! isset( $this->rows[ $id ] ) ) {
            return false;
        }
        $this->rows[ $id ]['status'] = $status;
        $this->rows[ $id ]['items']  = $items;

        return true;
    }

    public function trim( int $keep ): void
    {
        while ( count( $this->rows ) > $keep ) {
            unset( $this->rows[ array_key_first( $this->rows ) ] );
        }
    }
}
