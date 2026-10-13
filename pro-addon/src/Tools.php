<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Tools\AuditSite;
use AiEditorDivi5\Pro\Tools\BuildSite;
use AiEditorDivi5\Pro\Tools\CreateProduct;
use AiEditorDivi5\Pro\Tools\EditThemeLayout;
use AiEditorDivi5\Pro\Tools\FindReplaceSite;
use AiEditorDivi5\Pro\Tools\ListBatches;
use AiEditorDivi5\Pro\Tools\ListThemeLayouts;
use AiEditorDivi5\Pro\Tools\ManageGlobalColors;
use AiEditorDivi5\Pro\Tools\ManageGlobalPresets;
use AiEditorDivi5\Pro\Tools\ManageGlobalVariables;
use AiEditorDivi5\Pro\Tools\ManageThemeTemplate;
use AiEditorDivi5\Pro\Tools\ProposePhpSnippet;
use AiEditorDivi5\Pro\Tools\PublishPage;
use AiEditorDivi5\Pro\Tools\SetCustomCss;
use AiEditorDivi5\Pro\Tools\SetCustomFields;
use AiEditorDivi5\Pro\Tools\SetFrontPage;
use AiEditorDivi5\Pro\Tools\SetPrimaryMenu;
use AiEditorDivi5\Pro\Tools\UndoBatch;
use AiEditorDivi5\Pro\Tools\UpdateThemeLayout;

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
            new SetCustomCss( $log ),
            new SetPrimaryMenu( $log ),
            new PublishPage( $log ),
            new ProposePhpSnippet(),
            new ListBatches( $log ),
            new UndoBatch( $log, Batches::undo() ),
            new ListThemeLayouts(),
            new UpdateThemeLayout( $log ),
            new EditThemeLayout( $log ),
            new ManageThemeTemplate( $log ),
            new FindReplaceSite( $log ),
            new AuditSite(),
            new BuildSite( $log ),
            new ManageGlobalColors( $log ),
            new ManageGlobalVariables( $log ),
            new ManageGlobalPresets( $log ),
            new SetCustomFields( $log ),
            new CreateProduct( $log ),
        ];
    }
}
