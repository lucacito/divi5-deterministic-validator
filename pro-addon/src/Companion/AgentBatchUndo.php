<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Decides what undoing a batch of changes may touch, and does it. It never overwrites newer work and never deletes or trashes a
 * page: the most it does is put an earlier version of a page's content back, and the version it replaces is saved first, so the
 * undo can itself be undone.
 *
 * A page is skipped, with a reason, when:
 *  - it no longer exists (not_found) or the user may not edit it (forbidden),
 *  - it was changed after the batch: it has a history snapshot newer than the batch's last one on it (compared by version id, so
 *    not blurred by the one-second clock), or its modified time is later than the batch's last change on it by more than GRACE
 *    seconds, which catches saves that keep no snapshot (changed_afterwards),
 *  - the snapshot to go back to is gone (snapshot_missing).
 */
final class AgentBatchUndo
{
    /** Seconds between a snapshot being taken and the save completing. */
    public const GRACE = 10;

    /**
     * @param array<string,mixed> $batch one batch from AgentBatches::group()
     * @param callable(int):?array{modified_gmt:string, can_edit:bool} $page null when the page does not exist; modified_gmt "Y-m-d H:i:s" UTC
     * @param list<array{page_id:int, version_id:int, saved_at:string}> $rowsAfter every history row at or after the batch start, any source
     * @param callable(int,int):bool $snapshotExists
     * @return array{restore:list<array{page_id:int, version_id:int}>, skipped:list<array{page_id:int, reason:string, message:string}>}
     */
    public static function plan( array $batch, callable $page, array $rowsAfter, callable $snapshotExists ): array
    {
        $inBatch = [];
        foreach ( $batch['pages'] as $p ) {
            $inBatch[ $p['page_id'] ] = $p;
        }
        $restore = [];
        $skipped = [];
        foreach ( $inBatch as $pageId => $p ) {
            $info = $page( $pageId );
            if ( null === $info ) {
                $skipped[] = self::skip( $pageId, 'not_found', 'The page no longer exists.' );
                continue;
            }
            if ( ! $info['can_edit'] ) {
                $skipped[] = self::skip( $pageId, 'forbidden', 'You may not edit this page.' );
                continue;
            }
            $modified = strtotime( $info['modified_gmt'] . ' UTC' );
            $last     = strtotime( $p['last_saved_at'] );
            if ( false === $modified || false === $last || $modified > $last + self::GRACE ) {
                $skipped[] = self::skip( $pageId, 'changed_afterwards', 'The page was changed after this batch, so it was left alone.' );
                continue;
            }
            $newer = false;
            foreach ( $rowsAfter as $r ) {
                // Version ids count a page's snapshots exactly, so a change in the same second as the batch's last one is still seen.
                if ( $r['page_id'] === $pageId && $r['version_id'] > $p['last_version_id'] ) {
                    $newer = true;
                    break;
                }
            }
            if ( $newer ) {
                $skipped[] = self::skip( $pageId, 'changed_afterwards', 'Another change to this page was saved after this batch, so it was left alone.' );
                continue;
            }
            if ( ! $snapshotExists( $pageId, $p['restore_version_id'] ) ) {
                $skipped[] = self::skip( $pageId, 'snapshot_missing', 'The earlier version is no longer kept.' );
                continue;
            }
            $restore[] = [ 'page_id' => $pageId, 'version_id' => $p['restore_version_id'] ];
        }

        return [ 'restore' => $restore, 'skipped' => $skipped ];
    }

    /** @return array{page_id:int, reason:string, message:string} */
    private static function skip( int $pageId, string $reason, string $message ): array
    {
        return [ 'page_id' => $pageId, 'reason' => $reason, 'message' => $message ];
    }
}
