<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Canon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A page a site build created. `ref` is the page id, `before` is null (there was nothing), `after_hash` is a hash of the
 * title, content and slug read back after the insert, and `meta` carries what the launch needs (menu label, in the menu,
 * front page). Undo trashes the page, but only while it still looks the way the build left it (the changed-since rule): the
 * title, content and slug are unchanged AND it is still a draft, so a page the owner edited in the Divi builder, published or
 * made private by hand is skipped and reported, never trashed behind their back. (Divi keeps the layout in the content; a change of
 * parent, menu order, template or meta is not noticed.) Only a page is ever touched. The inverse is a
 * `page_trashed` item; with `EMPTY_TRASH_DAYS` 0 the trash is a permanent delete, so that inverse has no undo point.
 */
final class PageCreatedKind implements ItemKind
{
    public const NAME = 'page_created';

    /** @var (\Closure():bool)|null tests switch the trash setting with this */
    public static ?\Closure $trashEnabled = null;

    public function name(): string
    {
        return self::NAME;
    }

    /** Whether trashing a page keeps it (core: EMPTY_TRASH_DAYS above 0, default 30). */
    public static function trashEnabled(): bool
    {
        if ( null !== self::$trashEnabled ) {
            return (bool) ( self::$trashEnabled )();
        }

        return ! defined( 'EMPTY_TRASH_DAYS' ) || (int) EMPTY_TRASH_DAYS > 0;
    }

    /** @return array{title:string, content:string, slug:string} */
    public static function snapshot( object $post ): array
    {
        return [ 'title' => (string) $post->post_title, 'content' => (string) $post->post_content, 'slug' => (string) ( $post->post_name ?? '' ) ];
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public static function itemFor( object $post, array $meta ): array
    {
        $title = (string) $post->post_title;

        return Item::make( self::NAME, (string) $post->ID, "Created page \"{$title}\" (#{$post->ID})", null, self::snapshot( $post ) ) + [ 'meta' => $meta ];
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post && 'page' === $post->post_type && 'draft' === $post->post_status ? self::snapshot( $post ) : null;
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
        // `force` skips the changed-since rule, never this one: a page that is live (or private, or pending) is not the draft the build left.
        if ( 'draft' !== $post->post_status ) {
            return [ 'ok' => false, 'reason' => 'not_draft' ];
        }
        // With EMPTY_TRASH_DAYS 0 the trash is a permanent delete, so a page the owner changed is never deleted, forced or not.
        if ( ! self::trashEnabled() && Canon::hash( self::snapshot( $post ) ) !== (string) ( $item['after_hash'] ?? '' ) ) {
            return [ 'ok' => false, 'reason' => 'would_delete_permanently' ];
        }
        if ( ! wp_trash_post( $id ) ) {
            return [ 'ok' => false, 'reason' => 'trash_failed' ];
        }
        $undo = self::trashEnabled() ? null : [ 'available' => false, 'reason' => 'trash_disabled' ];

        return [ 'ok' => true, 'inverse' => Item::make( PageTrashedKind::NAME, (string) $id, (string) $item['label'], null, 'trash', $undo ) + [ 'meta' => $item['meta'] ?? [] ] ];
    }
}
