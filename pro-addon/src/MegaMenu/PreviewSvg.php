<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** STUB (to be completed): a small wireframe drawing of one layout, as an inline SVG string safe to echo. */
final class PreviewSvg
{
    /** @param array<string,mixed> $layout one entry of Layouts::all() */
    public static function for( array $layout ): string
    {
        return '<svg viewBox="0 0 240 150" role="img" aria-label="' . esc_attr( (string) $layout['name'] ) . '"></svg>';
    }
}
