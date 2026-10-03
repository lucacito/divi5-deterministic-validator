<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;
use AiEditorDivi5\WP\PageEditor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Find and replace literal text inside a live header or footer, with the free plugin's exact-replace rules. Same gate, history and undo as update_theme_layout. */
final class EditThemeLayout implements ProTool
{
    public const NAME = 'edit_theme_layout';

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
            'description' => 'Find and replace literal text inside a live Divi header or footer (layout_id from list_theme_layouts), leaving the rest untouched. The result must pass every layout check. It shows on every page: use dry_run=true first. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'layout_id'    => [ 'type' => 'integer', 'description' => 'A header or footer layout id from list_theme_layouts' ],
                    'find'         => [ 'type' => 'string', 'description' => 'Exact text to find (must match one place, unless expect_count says otherwise)' ],
                    'replace'      => [ 'type' => 'string', 'description' => 'Text to put in its place (may be empty)' ],
                    'expect_count' => [ 'type' => 'integer', 'description' => 'Optional. Replace all of exactly this many matches' ],
                    'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'layout_id', 'find', 'replace' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id      = (int) Args::int( $arguments, 'layout_id' );
        $find    = (string) Args::string( $arguments, 'find', true, LayoutWriter::MAX_BYTES );
        $replace = Args::string( $arguments, 'replace', false, LayoutWriter::MAX_BYTES );
        if ( null === $replace ) {
            throw new \InvalidArgumentException( '"replace" is required (it may be an empty string).' );
        }
        $expect = Args::int( $arguments, 'expect_count', false );
        $dry    = Args::bool( $arguments, 'dry_run' );

        return $this->writer->apply(
            $id,
            self::NAME,
            static function ( string $stored ) use ( $find, $replace, $expect ): array {
                $edit = PageEditor::apply( $stored, $find, $replace, $expect );
                if ( ! $edit['ok'] ) {
                    throw new \InvalidArgumentException( $edit['error'] );
                }

                return [ 'content' => $edit['content'], 'extra' => [ 'replaced' => $edit['count'] ] ];
            },
            $dry
        );
    }
}
