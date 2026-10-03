<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\ProTool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Recent Pro changes and whether each can still be undone. Read-only. */
final class ListBatches implements ProTool
{
    public const NAME = 'list_batches';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'List recent Pro changes (batches), newest first: what each changed and whether it can still be undone with undo_batch. Administrators see all; other users see their own.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [ 'limit' => [ 'type' => 'integer', 'description' => 'How many batches (1-20, default 10)' ] ],
                'required'   => [],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $limit = Args::int( $arguments, 'limit', false, 1 ) ?? 10;
        if ( $limit > BatchLog::MAX ) {
            throw new \InvalidArgumentException( '"limit" must be at most ' . BatchLog::MAX . '.' );
        }
        $all     = current_user_can( 'manage_options' );
        $me      = (int) get_current_user_id();
        $batches = [];
        foreach ( $this->log->recent( BatchLog::MAX ) as $batch ) {
            if ( ! $all && (int) $batch['user_id'] !== $me ) {
                continue;
            }
            $changes = [];
            foreach ( $batch['items'] as $item ) {
                $change = [
                    'kind'           => (string) ( $item['kind'] ?? '' ),
                    'label'          => (string) ( $item['label'] ?? '' ),
                    'state'          => (string) ( $item['state'] ?? 'applied' ),
                    'undo_available' => ! empty( $item['undo']['available'] ),
                ];
                if ( ! $change['undo_available'] && ! empty( $item['undo']['reason'] ) ) {
                    $change['undo_reason'] = (string) $item['undo']['reason'];
                }
                $changes[] = $change;
            }
            $batches[] = BatchLog::describe( $batch ) + [ 'user_id' => (int) $batch['user_id'], 'changes' => $changes ];
            if ( count( $batches ) >= $limit ) {
                break;
            }
        }

        return [ 'batches' => $batches ];
    }
}
