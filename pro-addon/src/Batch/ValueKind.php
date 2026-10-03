<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The common case: the item's `before` is the whole previous value and putting it back is one write. */
abstract class ValueKind implements ItemKind
{
    /** Write $value to the target. Return false when it could not be written. */
    abstract protected function write( array $item, mixed $value ): bool;

    public function restore( array $item ): array
    {
        $now = $this->current( $item );
        if ( ! $this->write( $item, $item['before'] ?? null ) ) {
            return [ 'ok' => false, 'reason' => 'write_failed' ];
        }

        return [ 'ok' => true, 'inverse' => Item::make( $this->name(), (string) $item['ref'], (string) $item['label'], $now, $item['before'] ?? null ) ];
    }
}
