<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Read-only: the live Theme Builder's templates with their header, footer and body layouts; one header or footer's content on request. */
final class ListThemeLayouts implements ProTool
{
    public const NAME = 'list_theme_layouts';

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
            'description' => 'List the live Divi Theme Builder templates and their header, footer and body layouts (read-only). With layout_id of a header or footer, also returns its content. Edit one with update_theme_layout or edit_theme_layout (dry_run first: it shows on every page).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'layout_id' => [ 'type' => 'integer', 'description' => 'Optional. A header or footer layout id from this list; adds its content to the answer' ],
                ],
                'required'   => [],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id = Args::int( $arguments, 'layout_id', false );
        if ( null === $id ) {
            return ThemeLayouts::listing();
        }
        $target = LayoutTarget::mustResolve( $id );

        return [
            'layout' => [
                'layout_id' => $id,
                'kind'      => $target['kind'],
                'title'     => (string) $target['post']->post_title,
                'content'   => (string) $target['post']->post_content,
            ],
        ];
    }
}
