<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

use AiEditorDivi5\Pro\Canon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Builds the stored record of one thing a batch changed. */
final class Item
{
    public const APPLIED = 'applied';
    public const UNDONE  = 'undone';

    /**
     * @param mixed $before what the target looked like before (kind-specific, JSON-safe; may be richer than the canonical value)
     * @param mixed $afterValue the canonical value the target has now: what the batch wrote (hashed, never stored)
     * @param array{available:bool, reason?:?string, version_id?:?int}|null $undoPoint null = an ordinary, available undo point
     * @return array<string,mixed>
     */
    public static function make( string $kind, string $ref, string $label, mixed $before, mixed $afterValue, ?array $undoPoint = null ): array
    {
        return [
            'kind'       => $kind,
            'ref'        => $ref,
            'label'      => $label,
            'before'     => $before,
            'after_hash' => Canon::hash( $afterValue ),
            'undo'       => $undoPoint ?? [ 'available' => true ],
            'state'      => self::APPLIED,
        ];
    }
}
