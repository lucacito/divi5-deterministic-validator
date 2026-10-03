<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\MenuBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Build the primary navigation menu from pages and links and assign it to the theme's menu location. One undoable batch. */
final class SetPrimaryMenu implements ProTool
{
    public const NAME = 'set_primary_menu';

    public function __construct( private BatchLog $log )
    {
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
            'description' => 'Build the primary menu ("Main Menu") from up to 30 items (a published page_id or a url each) and assign it to the theme\'s menu location. Replaces that menu\'s items. Undoable. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'items'   => [
                        'type'        => 'array',
                        'description' => 'Ordered menu items; page_id wins when both page_id and url are given',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'title'   => [ 'type' => 'string' ],
                                'page_id' => [ 'type' => 'integer', 'description' => 'A published page (preferred)' ],
                                'url'     => [ 'type' => 'string', 'description' => 'Or a custom link' ],
                            ],
                            'required'   => [ 'title' ],
                        ],
                    ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'items' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $items    = self::normalize( Args::listOf( $arguments, 'items', MenuBuilder::MAX_ITEMS ) );
        $dry      = Args::bool( $arguments, 'dry_run' );
        $location = MenuBuilder::targetLocation();
        $before   = MenuBuilder::snapshot( $location );
        $named    = '' !== $location ? $location : null;

        if ( $dry ) {
            return [
                'changed'      => false,
                'dry_run'      => true,
                'would_change' => [ 'menu' => MenuBuilder::MENU_NAME, 'location' => $named, 'replaces_existing_items' => count( $before['items'] ), 'new_items' => count( $items ) ],
            ];
        }

        $added = MenuBuilder::build( $items, $location );
        $after = MenuBuilder::snapshot( $location );
        $batch = $this->log->record(
            self::NAME,
            'Rebuilt the primary menu (' . $added . ' items)',
            [ Item::make( 'menu', $location, 'Main Menu (primary navigation)', $before, MenuBuilder::canonical( $after ) ) ]
        );

        $message = null === $named
            ? 'The menu "' . MenuBuilder::MENU_NAME . '" was built, but this theme has no menu location, so it was not assigned. Assign it under Appearance > Menus.'
            : 'The menu "' . MenuBuilder::MENU_NAME . '" was built and assigned to the "' . $location . '" location.';
        if ( $added < count( $items ) ) {
            $message .= ' ' . ( count( $items ) - $added ) . ' of ' . count( $items ) . ' items could not be added.';
        }

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'menu_id' => $after['menu_id'], 'items' => $added, 'location' => $named, 'message' => $message ], $batch );
    }

    /**
     * Validate the items and turn them into the snapshot item shape. A bad item rejects the whole call.
     *
     * @param list<mixed> $raw
     * @return list<array<string,mixed>>
     */
    private static function normalize( array $raw ): array
    {
        $out = [];
        foreach ( $raw as $i => $it ) {
            $n = $i + 1;
            if ( ! is_array( $it ) || array_is_list( $it ) ) {
                throw new \InvalidArgumentException( "Item {$n} must be an object." );
            }
            $title = isset( $it['title'] ) && is_string( $it['title'] ) ? sanitize_text_field( $it['title'] ) : '';
            if ( '' === $title ) {
                throw new \InvalidArgumentException( "Item {$n}: \"title\" is required and must be text." );
            }
            if ( strlen( $title ) > 100 ) {
                throw new \InvalidArgumentException( "Item {$n}: \"title\" must be at most 100 bytes." );
            }
            $base = [ 'title' => $title, 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ];

            $pageId = $it['page_id'] ?? null;
            $url    = $it['url'] ?? null;
            if ( null !== $pageId ) {
                if ( ! is_int( $pageId ) ) {
                    throw new \InvalidArgumentException( "Item {$n}: \"page_id\" must be an integer." );
                }
                $post = get_post( $pageId );
                if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
                    throw new \InvalidArgumentException( "Item {$n}: page {$pageId} was not found or is not published." );
                }
                $out[] = $base + [ 'type' => 'post_type', 'object' => 'page', 'object_id' => $pageId, 'url' => '' ];
            } elseif ( null !== $url ) {
                if ( ! is_string( $url ) ) {
                    throw new \InvalidArgumentException( "Item {$n}: \"url\" must be a string." );
                }
                $clean = esc_url_raw( $url );
                if ( '' === $clean ) {
                    throw new \InvalidArgumentException( "Item {$n}: \"url\" is not a valid link." );
                }
                $out[] = $base + [ 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => $clean ];
            } else {
                throw new \InvalidArgumentException( "Item {$n}: give a \"page_id\" or a \"url\"." );
            }
        }

        return $out;
    }
}
