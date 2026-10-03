<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\WP\HistoryService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A page's content. The previous content is NOT stored in the batch: the free plugin's history holds it, and the
 * item records the history version to restore. Restore goes through the free plugin's own `HistoryService::restore`
 * (one shared undo history), which snapshots the current content first, so the undo is itself undoable.
 */
final class PostContentKind implements ItemKind
{
    /** @param (\Closure(int,int):array<string,mixed>)|null $restorer fn(pageId, versionId): HistoryService::restore()'s result */
    public function __construct( private ?\Closure $restorer = null )
    {
    }

    public function name(): string
    {
        return 'post_content';
    }

    /**
     * The batch item for a write made through `HistoryService::write()`.
     *
     * @param array{stored?:bool, version_id?:?int, reason?:?string} $snapshot that call's `snapshot`
     * @return array<string,mixed>
     */
    public static function itemFor( int $pageId, string $title, string $newContent, array $snapshot ): array
    {
        return Item::make(
            'post_content',
            (string) $pageId,
            "Content of \"{$title}\" (#{$pageId})",
            null,
            $newContent,
            self::undoPoint( $snapshot )
        );
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post ? (string) $post->post_content : null;
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_post', (int) $item['ref'] );
    }

    public function restore( array $item ): array
    {
        $pageId    = (int) $item['ref'];
        $versionId = (int) ( $item['undo']['version_id'] ?? 0 );
        if ( $versionId < 1 ) {
            return [ 'ok' => false, 'reason' => 'no_version' ];
        }
        $restorer = $this->restorer ?? static fn ( int $p, int $v ): array => HistoryService::restore( $p, $v );
        $r        = $restorer( $pageId, $versionId );
        if ( empty( $r['ok'] ) ) {
            return [ 'ok' => false, 'reason' => (string) ( $r['error'] ?? 'restore_failed' ) ];
        }

        return [
            'ok'      => true,
            'inverse' => Item::make(
                'post_content',
                (string) $pageId,
                (string) $item['label'],
                null,
                $this->current( $item ),
                self::undoPoint( is_array( $r['snapshot'] ?? null ) ? $r['snapshot'] : [] )
            ),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{available:bool, reason:?string, version_id:?int}
     */
    private static function undoPoint( array $snapshot ): array
    {
        return [
            'available'  => (bool) ( $snapshot['stored'] ?? false ),
            'reason'     => isset( $snapshot['reason'] ) ? (string) $snapshot['reason'] : null,
            'version_id' => isset( $snapshot['version_id'] ) ? (int) $snapshot['version_id'] : null,
        ];
    }
}
