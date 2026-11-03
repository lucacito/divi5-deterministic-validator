<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** STUB (to be completed): the "Mega menu" tab of the plugin's admin page. */
final class MegaMenuPanel
{
    use AdminActions;

    public const TAB          = 'megamenu';
    public const ACTION_APPLY  = 'aied_pro_megamenu_apply';
    public const ACTION_REMOVE = 'aied_pro_megamenu_remove';

    public function __construct( private \Closure $unlocked, private MegaMenuService $service, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
    }
}
