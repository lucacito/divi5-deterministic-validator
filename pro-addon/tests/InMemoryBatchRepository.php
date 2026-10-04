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
    public bool $failAppend = false;
    public bool $failUpdate = false;
    /** Fail only the Nth append call (1-based; 0 = never): a build whose later step could not be recorded. */
    public int $failAppendAt = 0;
    private int $appends = 0;
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
        if ( $this->failUpdate || ! isset( $this->rows[ $id ] ) ) {
            return false;
        }
        $this->rows[ $id ]['status'] = $status;
        $this->rows[ $id ]['items']  = $items;

        return true;
    }

    public function append( int $id, array $items ): bool
    {
        if ( $this->failAppend || ( $this->failAppendAt > 0 && ++$this->appends === $this->failAppendAt ) || ! isset( $this->rows[ $id ] ) || 'building' !== $this->rows[ $id ]['status'] ) {
            return false;
        }
        $this->rows[ $id ]['items'] = array_merge( $this->rows[ $id ]['items'], array_values( $items ) );

        return true;
    }

    public function openBuild( int $userId ): ?array
    {
        foreach ( array_reverse( $this->rows, true ) as $row ) {
            if ( 'building' === $row['status'] && (int) $row['user_id'] === $userId ) {
                return $row;
            }
        }

        return null;
    }

    public function trim( int $keep ): void
    {
        // Like the real store: an open build is never removed and does not count towards the kept batches.
        $closed = array_keys( array_filter( $this->rows, static fn ( array $r ): bool => 'building' !== $r['status'] ) );
        while ( count( $closed ) > $keep ) {
            unset( $this->rows[ array_shift( $closed ) ] );
        }
    }
}
