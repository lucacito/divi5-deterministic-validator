<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\ProTool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Undo one batch, honestly: restore what can be restored, report the rest, never overwrite a later change. */
final class UndoBatch implements ProTool
{
    public const NAME = 'undo_batch';

    public function __construct( private BatchLog $log, private BatchUndo $undo )
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
            'description' => 'Undo one batch from list_batches. Restores every item it safely can and reports the rest; an item changed since is skipped unless force=true. Claims "fully undone" only if all items restored. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'batch_id' => [ 'type' => 'integer', 'description' => 'Batch id from list_batches' ],
                    'force'    => [ 'type' => 'boolean', 'description' => 'Restore items even if they changed since (never invents a missing undo point)' ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'batch_id' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $id    = (int) Args::int( $arguments, 'batch_id' );
        $force = Args::bool( $arguments, 'force' );
        $dry   = Args::bool( $arguments, 'dry_run' );

        // A batch the user may not see looks exactly like a batch that does not exist.
        $batch = $this->log->find( $id );
        if ( null === $batch || ( ! current_user_can( 'manage_options' ) && (int) $batch['user_id'] !== (int) get_current_user_id() ) ) {
            throw new \InvalidArgumentException( "Batch {$id} not found." );
        }

        $result = $this->undo->undo( $id, $force, $dry );
        if ( empty( $result['ok'] ) ) {
            throw new \InvalidArgumentException( (string) ( $result['message'] ?? "Batch {$id} not found." ) );
        }
        $result['message'] = self::message( $id, $result );
        if ( ! $dry && ! $result['fully_undone'] && BatchLog::BUILDING === ( $batch['status'] ?? '' ) ) {
            $result['message'] .= ' The build is closed; call add_page without build_id to start a new one. Pages that were kept stay as drafts.';
        }

        return $result;
    }

    /** @param array<string,mixed> $r */
    private static function message( int $id, array $r ): string
    {
        if ( $r['dry_run'] ) {
            return "Preview: {$r['would_restore']} item(s) of batch #{$id} would be restored; nothing was changed.";
        }
        if ( $r['fully_undone'] ) {
            return "Everything in batch #{$id} was undone.";
        }
        $skipped = [];
        foreach ( $r['items'] as $item ) {
            if ( ! in_array( $item['result'], [ 'restored', 'already_undone' ], true ) ) {
                $skipped[] = $item['label'] . ' (' . $item['result'] . ( isset( $item['reason'] ) && '' !== (string) $item['reason'] ? ': ' . $item['reason'] : '' ) . ')';
            }
        }

        return "Batch #{$id} was NOT fully undone. Restored {$r['restored']} item(s); skipped: " . implode( '; ', $skipped ) . '.';
    }
}
