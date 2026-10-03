<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The five storage operations the batch log needs. A row is
 * ['id'=>int, 'user_id'=>int, 'tool'=>string, 'created_at'=>'Y-m-d H:i:s' (UTC), 'summary'=>string,
 *  'status'=>'applied'|'undone'|'partly_undone', 'items'=>list<array>].
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

    /** Delete everything but the newest $keep rows. */
    public function trim( int $keep ): void;
}
