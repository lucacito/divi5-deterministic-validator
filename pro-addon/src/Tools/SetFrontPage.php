<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\ProTool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Set a published page as the static front page. One undoable batch. */
final class SetFrontPage implements ProTool
{
    public const NAME = 'set_front_page';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'manage_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Set a published page as the site\'s static front page (homepage). Recorded as an undoable batch. Pass dry_run=true to preview without changing anything.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'page_id' => [ 'type' => 'integer', 'description' => 'ID of a published page' ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'page_id' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $pageId = (int) Args::int( $arguments, 'page_id' );
        $dry    = Args::bool( $arguments, 'dry_run' );

        $post = get_post( $pageId );
        if ( ! $post || 'page' !== $post->post_type ) {
            throw new \InvalidArgumentException( "Page {$pageId} not found." );
        }
        if ( 'publish' !== $post->post_status ) {
            throw new \InvalidArgumentException( "Page {$pageId} is not published. Publish it first (publish_page), then set it as the front page." );
        }

        $title  = (string) get_the_title( $post );
        $page   = [ 'id' => $pageId, 'title' => $title ];
        $before = FrontPageKind::read();
        $after  = [ 'show_on_front' => 'page', 'page_on_front' => $pageId ];

        if ( $before === $after ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'front_page' => $page, 'message' => 'That page is already the front page; nothing changed.' ];
        }
        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'front_page' => $page, 'would_change' => [ 'from' => $before, 'to' => $after ] ];
        }

        FrontPageKind::set( 'page', $pageId );
        $batch = $this->log->record(
            self::NAME,
            "Set front page to \"{$title}\" (#{$pageId})",
            [ Item::make( 'front_page', 'front_page', 'Front page', $before, FrontPageKind::read() ) ]
        );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'front_page' => $page ], $batch );
    }
}
