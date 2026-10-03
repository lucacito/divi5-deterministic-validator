<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\WP\EditTarget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Publish a draft or pending page now, or schedule it. One undoable batch; the page content is never touched. */
final class PublishPage implements ProTool
{
    public const NAME = 'publish_page';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'publish_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Publish a draft or pending page now, or schedule it with publish_at (ISO 8601 with a timezone, in the future). Does not change the page content. Undoable. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'page_id'    => [ 'type' => 'integer', 'description' => 'ID of a draft or pending page' ],
                    'publish_at' => [ 'type' => 'string', 'description' => 'Optional. Schedule instead of publishing now, e.g. 2026-12-01T09:00:00+01:00' ],
                    'dry_run'    => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'page_id' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $pageId = (int) Args::int( $arguments, 'page_id' );
        $at     = Args::string( $arguments, 'publish_at', false, 40 );
        $dry    = Args::bool( $arguments, 'dry_run' );

        $target = EditTarget::resolve( $pageId );
        if ( EditTarget::NOT_FOUND === $target['error'] ) {
            throw new \InvalidArgumentException( "Page {$pageId} not found." );
        }
        if ( EditTarget::FORBIDDEN === $target['error'] || null === $target['post'] ) {
            throw new \InvalidArgumentException( "You do not have permission to edit page {$pageId}." );
        }
        $post   = $target['post'];
        $status = (string) $post->post_status;
        if ( ! in_array( $status, [ 'draft', 'pending' ], true ) ) {
            throw new \InvalidArgumentException( "Page {$pageId} is {$status}; only a draft or pending page can be published or scheduled." );
        }

        $gmt = null === $at ? null : self::parseFuture( $at );
        $to  = null === $gmt ? 'publish' : 'future';
        $title = (string) $post->post_title;

        if ( $dry ) {
            return [
                'changed'      => false,
                'dry_run'      => true,
                'page'         => [ 'id' => $pageId, 'title' => $title, 'status' => $status ],
                'would_change' => [ 'status' => [ 'from' => $status, 'to' => $to ], 'publish_at' => $at ],
            ];
        }

        $update = [ 'ID' => $pageId, 'post_status' => $to ];
        if ( null !== $gmt ) {
            $update['post_date_gmt'] = $gmt;
            $update['post_date']     = get_date_from_gmt( $gmt );
            $update['edit_date']     = true;
        }
        $saved = wp_update_post( $update, true );
        if ( is_wp_error( $saved ) ) {
            throw new \RuntimeException( $saved->get_error_message() );
        }

        // Record what the page really has now: WordPress decides the final status.
        $now   = get_post( $pageId );
        $final = $now ? (string) $now->post_status : $to;
        $batch = $this->log->record(
            self::NAME,
            ( 'future' === $final ? 'Scheduled' : 'Published' ) . " \"{$title}\" (#{$pageId})",
            [ Item::make( 'post_status', (string) $pageId, "Status of \"{$title}\" (#{$pageId})", $status, $final ) ]
        );

        return BatchLog::attach(
            [ 'changed' => true, 'dry_run' => false, 'page' => [ 'id' => $pageId, 'title' => $title, 'status' => $final, 'link' => get_permalink( $pageId ) ] ],
            $batch
        );
    }

    /** ISO 8601 with a timezone, strictly parsed, at least a minute from now. Returns UTC `Y-m-d H:i:s`. */
    private static function parseFuture( string $at ): string
    {
        $invalid = 'publish_at must be an ISO 8601 date and time with a timezone, for example 2026-12-01T09:00:00+01:00 or 2026-12-01T09:00:00Z.';
        if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/', $at ) ) {
            throw new \InvalidArgumentException( $invalid );
        }
        $norm = preg_replace( '/Z$/', '+00:00', $at );
        $norm = (string) preg_replace( '/T(\d{2}:\d{2})(?=[+-])/', 'T$1:00', $norm );
        $dt   = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:sP', $norm );
        $err  = \DateTimeImmutable::getLastErrors();
        if ( false === $dt || ( is_array( $err ) && ( $err['warning_count'] > 0 || $err['error_count'] > 0 ) ) ) {
            throw new \InvalidArgumentException( $invalid );
        }
        if ( $dt->getTimestamp() < time() + 60 ) {
            throw new \InvalidArgumentException( 'publish_at must be in the future (at least one minute from now).' );
        }

        return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }
}
