<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One add_page at a time per user. An MCP client may send tool calls in parallel; two of them must not both read the build,
 * both create a page and both write the batch back (one page would then be missing from the build and from its undo). The lock
 * is an option added atomically (`add_option` fails when it exists); a lock older than `$expirySeconds` belongs to a crashed
 * request and is taken over.
 */
final class BuildLock
{
    private const STEP_MS = 100;
    private const PREFIX  = 'aied_pro_buildlock_';

    public function __construct( private int $waitMs = 5000, private int $expirySeconds = 15 )
    {
    }

    public function acquire( int $userId ): bool
    {
        $key = self::PREFIX . $userId;
        for ( $waited = 0; ; $waited += self::STEP_MS ) {
            if ( add_option( $key, (string) time(), '', 'no' ) ) {
                return true;
            }
            $held = (int) get_option( $key, 0 );
            if ( $held > 0 && time() - $held >= $this->expirySeconds ) {
                delete_option( $key );
                if ( add_option( $key, (string) time(), '', 'no' ) ) {
                    return true;
                }
            }
            if ( $waited >= $this->waitMs ) {
                return false;
            }
            usleep( self::STEP_MS * 1000 );
        }
    }

    public function release( int $userId ): void
    {
        delete_option( self::PREFIX . $userId );
    }
}
