<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Records Pro changes as batches (the last 20 are kept) and hands them back for undo. */
final class BatchLog
{
    public const MAX = 20;

    /** The status of a batch that is still growing (an open build). */
    public const BUILDING = 'building';

    public function __construct( private BatchRepository $repo )
    {
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null the described batch, or null when nothing was recorded
     */
    public function record( string $tool, string $summary, array $items ): ?array
    {
        if ( [] === $items ) {
            return null;
        }
        $row = [
            'user_id'    => (int) get_current_user_id(),
            'tool'       => $tool,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'summary'    => mb_substr( $summary, 0, 255 ),
            'status'     => 'applied',
            'items'      => array_values( $items ),
        ];
        $id = $this->repo->insert( $row );
        if ( null === $id ) {
            return null;
        }
        $this->repo->trim( self::MAX );

        return self::describe( [ 'id' => $id ] + $row );
    }

    /**
     * Opens a batch that will grow: status `building`, no items yet. Closed with close() (or by an undo).
     *
     * @return array<string,mixed>|null the described batch, or null when it could not be stored
     */
    public function open( string $tool, string $summary ): ?array
    {
        $row = [
            'user_id'    => (int) get_current_user_id(),
            'tool'       => $tool,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'summary'    => mb_substr( $summary, 0, 255 ),
            'status'     => self::BUILDING,
            'items'      => [],
        ];
        $id = $this->repo->insert( $row );
        if ( null === $id ) {
            return null;
        }
        $this->repo->trim( self::MAX );

        return self::describe( [ 'id' => $id ] + $row );
    }

    /** @param list<array<string,mixed>> $items */
    public function append( int $id, array $items ): bool
    {
        return $this->repo->append( $id, $items );
    }

    /**
     * Sets the status of a batch (an open build becomes `applied`) and keeps its items. `$pageHashes` (page id => hash) replaces the
     * recorded after_hash of those `page_created` items: a slug WordPress changed at publish time is then the slug the undo expects.
     *
     * @param array<int,string> $pageHashes
     */
    public function close( int $id, string $status, array $pageHashes = [] ): bool
    {
        $batch = $this->repo->find( $id );
        if ( null === $batch ) {
            return false;
        }
        $items = $batch['items'];
        foreach ( $items as $i => $item ) {
            if ( 'page_created' === ( $item['kind'] ?? '' ) && isset( $pageHashes[ (int) ( $item['ref'] ?? 0 ) ] ) ) {
                $items[ $i ]['after_hash'] = $pageHashes[ (int) $item['ref'] ];
            }
        }

        return $this->repo->update( $id, $status, $items );
    }

    /** @return array<string,mixed>|null the user's open build */
    public function openBuild( int $userId ): ?array
    {
        return $this->repo->openBuild( $userId );
    }

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array
    {
        return $this->repo->find( $id );
    }

    /** @return list<array<string,mixed>> newest first */
    public function recent( int $limit = self::MAX ): array
    {
        return $this->repo->recent( $limit );
    }

    /** @param list<array<string,mixed>> $items */
    public function markUndone( int $id, string $status, array $items ): bool
    {
        return $this->repo->update( $id, $status, $items );
    }

    /**
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    public static function describe( array $batch ): array
    {
        $items  = is_array( $batch['items'] ?? null ) ? $batch['items'] : [];
        $points = 0;
        foreach ( $items as $item ) {
            if ( ! empty( $item['undo']['available'] ) && 'undone' !== ( $item['state'] ?? 'applied' ) ) {
                $points++;
            }
        }

        return [
            'id'          => (int) $batch['id'],
            'tool'        => (string) $batch['tool'],
            'summary'     => (string) $batch['summary'],
            'created_at'  => (string) $batch['created_at'],
            'status'      => (string) $batch['status'],
            'items'       => count( $items ),
            'undo_points' => $points,
            'undoable'    => $points > 0,
        ];
    }

    /**
     * Adds the batch to a tool's result. A write whose batch could not be recorded is still reported as made,
     * and says plainly that it cannot be undone.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $batch
     * @return array<string,mixed>
     */
    public static function attach( array $payload, ?array $batch ): array
    {
        $payload['batch'] = $batch ?? [
            'id'       => null,
            'undoable' => false,
            'note'     => 'The change was saved, but it could not be recorded, so undo_batch cannot undo it.',
        ];

        return $payload;
    }
}
