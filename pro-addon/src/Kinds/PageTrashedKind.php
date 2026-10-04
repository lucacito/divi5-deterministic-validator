<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The inverse of an undone `page_created`: the page is in the trash. Undoing it takes the page out of the trash again (core
 * brings it back as a DRAFT with its slug restored) and records a `page_created` item, so an undo can always be undone.
 */
final class PageTrashedKind implements ItemKind
{
    public const NAME = 'page_trashed';

    public function name(): string
    {
        return self::NAME;
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post && 'page' === $post->post_type ? (string) $post->post_status : null;
    }

    public function allowed( array $item ): bool
    {
        $id = (int) $item['ref'];

        return current_user_can( 'edit_post', $id ) && current_user_can( 'delete_post', $id );
    }

    public function restore( array $item ): array
    {
        $id   = (int) $item['ref'];
        $post = get_post( $id );
        if ( ! $post || 'page' !== $post->post_type ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        if ( ! wp_untrash_post( $id ) ) {
            return [ 'ok' => false, 'reason' => 'untrash_failed' ];
        }
        $post = get_post( $id );
        if ( ! is_object( $post ) ) {
            // Untrashed, but gone again before it could be read: nothing to take away a second time.
            return [ 'ok' => true, 'inverse' => Item::make( PageCreatedKind::NAME, (string) $id, (string) $item['label'], null, null, [ 'available' => false, 'reason' => 'missing' ] ) ];
        }

        return [ 'ok' => true, 'inverse' => PageCreatedKind::itemFor( $post, is_array( $item['meta'] ?? null ) ? $item['meta'] : [] ) ];
    }
}
