<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Replace the whole content of a live header or footer. Validated, snapshotted, undoable; dry_run previews. */
final class UpdateThemeLayout implements ProTool
{
    public const NAME = 'update_theme_layout';

    private LayoutWriter $writer;

    public function __construct( BatchLog $log, ?LayoutWriter $writer = null )
    {
        $this->writer = $writer ?? new LayoutWriter( $log );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Replace the content of a live Divi header or footer (layout_id from list_theme_layouts) with a new Divi 5 layout. Rejected with the exact violations unless it passes every check. It shows on every page: use dry_run=true first. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'layout_id' => [ 'type' => 'integer', 'description' => 'A header or footer layout id from list_theme_layouts' ],
                    'content'   => [ 'type' => 'string', 'description' => 'The full Divi 5 layout (block markup) for this header or footer' ],
                    'dry_run'   => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'layout_id', 'content' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id      = (int) Args::int( $arguments, 'layout_id' );
        $content = (string) Args::string( $arguments, 'content', true, LayoutWriter::MAX_BYTES );
        $dry     = Args::bool( $arguments, 'dry_run' );

        return $this->writer->apply( $id, self::NAME, static fn ( string $stored ): array => [ 'content' => $content, 'extra' => [] ], $dry );
    }
}
