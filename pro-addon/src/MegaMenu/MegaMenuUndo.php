<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * "Undo last change" for the Mega menu tab. Every apply and every remove is one batch of the tool `apply_mega_menu`; the
 * newest one that can still be undone is the one this class offers, and BatchUndo puts the header back byte for byte (and
 * refuses, touching nothing, when the header was changed since, e.g. in the Divi builder).
 */
final class MegaMenuUndo
{
    public function __construct( private BatchLog $log, private BatchUndo $undo )
    {
    }

    /**
     * The newest undoable mega menu change the user may undo, as BatchLog::describe() shows it, or null.
     *
     * @return array<string,mixed>|null
     */
    public function last( int $userId, bool $mayUndoAll ): ?array
    {
        foreach ( $this->log->recent( 30 ) as $batch ) {
            if ( MegaMenuService::TOOL !== (string) $batch['tool'] ) {
                continue;
            }
            if ( ! $mayUndoAll && (int) $batch['user_id'] !== $userId ) {
                continue;
            }
            $d = BatchLog::describe( $batch );
            if ( $d['undoable'] ) {
                return $d;
            }
        }

        return null;
    }

    /**
     * Undoes that change.
     *
     * @return array{result:string,summary:string} result: `undone` | `partly` | `nothing_restored` | `none` (there was nothing to undo)
     */
    public function undoLast( int $userId, bool $mayUndoAll ): array
    {
        $last = $this->last( $userId, $mayUndoAll );
        if ( null === $last ) {
            return [ 'result' => 'none', 'summary' => '' ];
        }
        $r = $this->undo->undo( (int) $last['id'] );

        return [
            'result'  => ! empty( $r['fully_undone'] ) ? 'undone' : ( (int) ( $r['restored'] ?? 0 ) > 0 ? 'partly' : 'nothing_restored' ),
            'summary' => (string) $last['summary'],
        ];
    }
}
