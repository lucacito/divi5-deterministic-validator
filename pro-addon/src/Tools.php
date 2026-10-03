<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Tools\SetFrontPage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The list of Pro tools. */
final class Tools
{
    /** @return list<ProTool> */
    public static function all(): array
    {
        $log = Batches::log();

        return [
            new SetFrontPage( $log ),
        ];
    }
}
