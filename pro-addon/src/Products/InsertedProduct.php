<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which post a FAILED product save may delete. Pure (no WordPress call), so it is unit-tested; WooProductWriter uses it for the
 * insert listener and again on the re-read post right before it deletes anything.
 */
final class InsertedProduct
{
    /**
     * True only for the draft THIS save inserted: a product post, status draft, a real id and our name as its title. Core's
     * title_save_pre (kses, for a user without unfiltered_html) stores a bare "&" as "&amp;", so that one rewrite is undone first.
     */
    public static function matches( mixed $post, string $name ): bool
    {
        if ( '' === $name || ! is_object( $post ) || ! is_string( $post->post_title ?? null ) ) {
            return false;
        }

        return 'product' === ( $post->post_type ?? '' )
            && 'draft' === ( $post->post_status ?? '' )
            && (int) ( $post->ID ?? 0 ) > 0
            && ( $name === $post->post_title || $name === str_replace( '&amp;', '&', $post->post_title ) );
    }

    /** The one id a failed save may consider: the product object's own id when WooCommerce set it, else the recorded one; 0 = none. */
    public static function candidate( int $objectId, int $recorded ): int
    {
        if ( $objectId > 0 ) {
            return $objectId;
        }

        return max( 0, $recorded );
    }
}
