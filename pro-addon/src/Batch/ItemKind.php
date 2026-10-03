<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** How one kind of target (an option, a CSS block, a menu, a page's status or content) is read and put back. */
interface ItemKind
{
    /** The `kind` string stored in items. */
    public function name(): string;

    /** The canonical value of the target right now: its `Canon::hash` is compared with the item's `after_hash`. */
    public function current( array $item ): mixed;

    /** Whether the current user may restore this item (re-checked at undo time). */
    public function allowed( array $item ): bool;

    /**
     * Put the previous value back.
     *
     * @return array{ok:true, inverse:array<string,mixed>}|array{ok:false, reason:string} the inverse is the item that undoes this undo
     */
    public function restore( array $item ): array;
}
