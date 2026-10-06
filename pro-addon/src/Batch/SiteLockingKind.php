<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * An item kind whose target is shared site-wide state that a write REPLACES as a whole (Divi's global colors) and that the
 * platform caches for the rest of the request. Two requests that each read it and then write their own copy erase each other's
 * change, so a real undo must hold the kind's lock from BEFORE its first read of the target until its last write: BatchUndo
 * takes it once for the whole batch, never per item (a lock taken after a read would still write that read's stale copy).
 */
interface SiteLockingKind extends ItemKind
{
    /** Take the site-wide lock; false when another request holds it. */
    public function lockSite(): bool;

    public function unlockSite(): void;
}
