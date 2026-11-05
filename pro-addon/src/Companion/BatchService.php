<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

use AiEditorDivi5\WP\EditTarget;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Lists and undoes batches of changes made by any agent. WordPress-facing glue around AgentBatches and AgentBatchUndo. */
final class BatchService
{
    /** How far back batches are looked for. */
    public const DAYS = 3;

    /**
     * Batches the user may see: their own, or all of them for someone who can manage options.
     *
     * @return list<array<string,mixed>>
     */
    public static function listBatches(): array
    {
        $since = gmdate( 'Y-m-d\TH:i:s\Z', time() - self::DAYS * DAY_IN_SECONDS );
        $rows  = AgentBatches::onlyPages( HistoryStore::rowsSince( $since, 1000 ), static fn ( int $id ): ?string => get_post_type( $id ) ?: null, EditTarget::ALLOWED_TYPES );
        $all   = AgentBatches::group( $rows, AgentSessions::all() );
        if ( current_user_can( 'manage_options' ) ) {
            return $all;
        }
        $me = (int) get_current_user_id();

        return array_values( array_filter( $all, static fn ( array $b ): bool => $b['actor'] === $me ) );
    }

    /**
     * Undoes one batch. Never overwrites a newer change and never deletes or trashes a page.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function undo( string $id, bool $dryRun = false )
    {
        $batch = null;
        foreach ( self::listBatches() as $b ) {
            if ( $b['id'] === $id ) {
                $batch = $b;
                break;
            }
        }
        if ( null === $batch ) {
            return new \WP_Error( 'jhmg_batch_not_found', 'No such batch (batches are kept from the last ' . self::DAYS . ' days, and you see your own).', [ 'status' => 404 ] );
        }
        $rowsAfter = AgentBatches::onlyPages( HistoryStore::rowsSince( (string) $batch['started'], 2000 ), static fn ( int $id ): ?string => get_post_type( $id ) ?: null, EditTarget::ALLOWED_TYPES );
        $plan      = AgentBatchUndo::plan(
            $batch,
            static function ( int $pageId ): ?array {
                $target = EditTarget::resolve( $pageId );
                if ( null === $target['post'] ) {
                    return EditTarget::FORBIDDEN === $target['error'] ? [ 'modified_gmt' => '1970-01-01 00:00:00', 'can_edit' => false ] : null;
                }

                return [ 'modified_gmt' => (string) $target['post']->post_modified_gmt, 'can_edit' => true ];
            },
            $rowsAfter,
            static fn ( int $pageId, int $versionId ): bool => null !== HistoryService::entry( $pageId, $versionId )
        );

        if ( $dryRun ) {
            return [ 'batch' => $id, 'dry_run' => true, 'would_restore' => $plan['restore'], 'would_skip' => $plan['skipped'], 'message' => 'Preview only. Nothing was changed.' ];
        }
        $restored = [];
        $skipped  = $plan['skipped'];
        foreach ( $plan['restore'] as $r ) {
            $done = HistoryService::restore( $r['page_id'], $r['version_id'] );
            if ( $done['ok'] ) {
                $restored[] = [ 'page_id' => $r['page_id'], 'restored_version' => $r['version_id'], 'undoable' => (bool) ( $done['snapshot']['stored'] ?? false ) ];
            } else {
                $skipped[] = [ 'page_id' => $r['page_id'], 'reason' => 'restore_failed', 'message' => (string) ( $done['message'] ?? 'The page could not be restored.' ) ];
            }
        }

        return [
            'batch'         => $id,
            'restored'      => $restored,
            'skipped'       => $skipped,
            'fully_undone'  => [] === $skipped && [] !== $restored,
            'message'       => [] === $skipped ? 'Every page in the batch was put back.' : count( $skipped ) . ' page(s) were left alone, each with its reason. Nothing newer was overwritten and no page was deleted.',
        ];
    }
}
