<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

use AiEditorDivi5\Pro\Kinds\CssKind;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\Kinds\GlobalColorKind;
use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;
use AiEditorDivi5\Pro\Kinds\MenuKind;
use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
use AiEditorDivi5\Pro\Kinds\PageTrashedKind;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use AiEditorDivi5\Pro\Kinds\PostStatusKind;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\Pro\Kinds\ThemeTemplateKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The shared batch log, the registry of item kinds and the undo engine built on them. */
final class Batches
{
    private static ?BatchLog $log   = null;
    private static ?BatchUndo $undo = null;

    public static function log(): BatchLog
    {
        return self::$log ??= new BatchLog( new WpdbBatchRepository() );
    }

    /** @return array<string,ItemKind> keyed by kind name */
    public static function kinds(): array
    {
        $kinds = [
            new FrontPageKind(),
            new CssKind(),
            new MenuKind(),
            new PostStatusKind(),
            new PageCreatedKind(),
            new PageTrashedKind(),
            new PostContentKind(),
            new ThemeLayoutKind(),
            new ThemeTemplateKind(),
            new GlobalColorKind(),
            new GlobalVariableKind(),
        ];
        $out = [];
        foreach ( $kinds as $kind ) {
            $out[ $kind->name() ] = $kind;
        }

        return $out;
    }

    public static function undo(): BatchUndo
    {
        return self::$undo ??= new BatchUndo( self::kinds(), self::log() );
    }

    /** @internal test isolation only */
    public static function resetForTests(): void
    {
        self::$log  = null;
        self::$undo = null;
    }
}
