<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

use AiEditorDivi5\Pro\Canon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Undoes a batch honestly: every item that can be restored is, the rest are reported with the reason, and
 * "fully undone" is claimed only when every item is back. An item whose target changed since the batch wrote
 * it is never overwritten (unless `force`), and an item without an undo point is never invented one.
 */
final class BatchUndo
{
    /** @param array<string,ItemKind> $kinds keyed by kind name */
    public function __construct( private array $kinds, private BatchLog $log )
    {
    }

    /** @return array<string,mixed> */
    public function undo( int $batchId, bool $force = false, bool $dryRun = false ): array
    {
        $batch = $this->log->find( $batchId );
        if ( null === $batch ) {
            return [ 'ok' => false, 'error' => 'not_found', 'message' => "Batch {$batchId} not found." ];
        }

        $items    = $batch['items'];
        $report   = [];
        $inverse  = [];
        $restored = 0;
        $would    = 0;
        // A kind over shared site-wide state is locked once, before the batch's first read of it and until its last write (see
        // SiteLockingKind). A busy lock fails that kind's items without reading or writing anything; a dry run only reads.
        $locked = [];
        $busy   = [];
        if ( ! $dryRun ) {
            foreach ( $items as $item ) {
                $kind = $this->kinds[ (string) ( $item['kind'] ?? '' ) ] ?? null;
                if ( $kind instanceof SiteLockingKind && Item::UNDONE !== ( $item['state'] ?? Item::APPLIED ) && ! isset( $locked[ $kind->name() ] ) && ! isset( $busy[ $kind->name() ] ) ) {
                    if ( $kind->lockSite() ) {
                        $locked[ $kind->name() ] = $kind;
                    } else {
                        $busy[ $kind->name() ] = true;
                    }
                }
            }
        }
        try {
            for ( $i = count( $items ) - 1; $i >= 0; $i-- ) {
                $item  = $items[ $i ];
                $row   = [ 'kind' => (string) ( $item['kind'] ?? '' ), 'label' => (string) ( $item['label'] ?? '' ) ];
                $kind  = $this->kinds[ $row['kind'] ] ?? null;
                if ( null !== $kind && isset( $busy[ $kind->name() ] ) && Item::UNDONE !== ( $item['state'] ?? Item::APPLIED ) ) {
                    $report[ $i ] = $row + [ 'result' => 'failed', 'reason' => 'busy' ];
                    continue;
                }
                try {
                    $check = $this->check( $item, $kind, $force );
                } catch ( \Throwable $e ) {
                    // A kind that cannot even read its target (e.g. a menu deleted since) fails this item only; the loop goes on.
                    $check = [ 'result' => 'failed', 'reason' => 'exception' ];
                }
                if ( null !== $check ) {
                    $report[ $i ] = $row + $check;
                    continue;
                }
                if ( $dryRun ) {
                    $would++;
                    $report[ $i ] = $row + [ 'result' => 'would_restore' ];
                    continue;
                }
                try {
                    $r = $kind->restore( $item );
                } catch ( \Throwable $e ) {
                    $r = [ 'ok' => false, 'reason' => 'exception' ];
                }
                if ( empty( $r['ok'] ) ) {
                    $report[ $i ] = $row + [ 'result' => 'failed', 'reason' => (string) ( $r['reason'] ?? 'failed' ) ];
                    continue;
                }
                $restored++;
                $items[ $i ]['state'] = Item::UNDONE;
                $inverse[]            = $r['inverse'];
                $report[ $i ]         = $row + [ 'result' => 'restored' ];
            }
        } finally {
            foreach ( $locked as $kind ) {
                $kind->unlockSite();
            }
        }
        ksort( $report );

        $allUndone = true;
        foreach ( $items as $item ) {
            if ( Item::UNDONE !== ( $item['state'] ?? Item::APPLIED ) ) {
                $allUndone = false;
            }
        }

        $undoBatch = null;
        // An open build is closed by every real undo, even one that restored nothing: otherwise a build whose pages were all edited
        // since would stay open for ever and its owner could never start another.
        if ( ! $dryRun && ( $restored > 0 || BatchLog::BUILDING === ( $batch['status'] ?? '' ) ) ) {
            // Mark the original first: recording the undo trims to the newest 20 and may remove the oldest batch.
            $this->log->markUndone( $batchId, $allUndone ? 'undone' : 'partly_undone', $items );
            if ( $restored > 0 ) {
                $undoBatch = $this->log->record( 'undo_batch', 'Undo of batch #' . $batchId . ': ' . $batch['summary'], $inverse );
            }
        }

        return [
            'ok'            => true,
            'batch_id'      => $batchId,
            'dry_run'       => $dryRun,
            'restored'      => $restored,
            'would_restore' => $would,
            'fully_undone'  => ! $dryRun && $allUndone,
            'items'         => array_values( $report ),
            'undo_batch'    => $undoBatch,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null null = this item may be restored; otherwise its report fields
     */
    private function check( array $item, ?ItemKind $kind, bool $force ): ?array
    {
        if ( Item::UNDONE === ( $item['state'] ?? Item::APPLIED ) ) {
            return [ 'result' => 'already_undone' ];
        }
        if ( null === $kind ) {
            return [ 'result' => 'unknown_kind' ];
        }
        if ( empty( $item['undo']['available'] ) ) {
            return [ 'result' => 'no_undo_point', 'reason' => $item['undo']['reason'] ?? null ];
        }
        if ( ! $kind->allowed( $item ) ) {
            return [ 'result' => 'forbidden' ];
        }
        if ( ! $force && Canon::hash( $kind->current( $item ) ) !== (string) ( $item['after_hash'] ?? '' ) ) {
            return [ 'result' => 'changed_since' ];
        }

        return null;
    }
}
