<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The seven storage operations the batch log needs. A row is
 * ['id'=>int, 'user_id'=>int, 'tool'=>string, 'created_at'=>'Y-m-d H:i:s' (UTC), 'summary'=>string,
 *  'status'=>'applied'|'undone'|'partly_undone'|'building', 'items'=>list<array>]. A `building` row is an OPEN
 * build (a batch that still grows): it is never trimmed and does not count towards the kept batches.
 */
interface BatchRepository
{
    /** @param array<string,mixed> $row a row without `id` @return ?int the new id, null when it could not be stored */
    public function insert( array $row ): ?int;

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array;

    /** @return list<array<string,mixed>> newest first */
    public function recent( int $limit ): array;

    /** @param list<array<string,mixed>> $items */
    public function update( int $id, string $status, array $items ): bool;

    /** Add items after the stored ones, only to a batch that is still `building`. False when it does not exist, is closed or the write failed. @param list<array<string,mixed>> $items */
    public function append( int $id, array $items ): bool;

    /** The newest `building` batch of this user, or null. @return array<string,mixed>|null */
    public function openBuild( int $userId ): ?array;

    /** Delete everything but the newest $keep rows that are not open builds. */
    public function trim( int $keep ): void;
}
