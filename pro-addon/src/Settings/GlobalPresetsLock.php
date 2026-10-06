<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

use AiEditorDivi5\Pro\Site\BuildLock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One global-presets write at a time on the whole site. Divi's save REPLACES the complete preset data, so two requests that
 * each read the data and then write their own copy would silently erase each other's change (and Divi caches its read for the
 * rest of the request). This lock covers the whole read, write and read-back of every Pro preset change and undo: take it
 * BEFORE the request's first read. It is the build lock's atomic database row under a fixed name that starts with the build-lock
 * prefix, so uninstall.php already removes it. A separate lock from the colors and variables ones: presets live in a different
 * option. It cannot stop a save made in the Divi builder itself, which knows nothing of this lock.
 */
final class GlobalPresetsLock
{
    public const PREFIX = 'aied_pro_buildlock_gpresets_';

    private BuildLock $lock;

    public function __construct( int $waitMs = 5000, int $expirySeconds = 30 )
    {
        $this->lock = new BuildLock( $waitMs, $expirySeconds, self::PREFIX );
    }

    public function acquire(): bool
    {
        return $this->lock->acquire( 0 );
    }

    public function release(): void
    {
        $this->lock->release( 0 );
    }
}
