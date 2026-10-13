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
     * True only for the draft THIS save inserted: a product post, status draft, a real id and our name as its title (compared with
     * ProductValuePolicy::sameText, because core's title filter stores a bare "&" as "&amp;" for a user without unfiltered_html).
     */
    public static function matches( mixed $post, string $name ): bool
    {
        if ( '' === $name || ! is_object( $post ) || ! is_string( $post->post_title ?? null ) ) {
            return false;
        }

        return 'product' === ( $post->post_type ?? '' )
            && 'draft' === ( $post->post_status ?? '' )
            && (int) ( $post->ID ?? 0 ) > 0
            && ProductValuePolicy::sameText( $name, $post->post_title );
    }

    /**
     * The one id a failed save may consider: the product object's own id when WooCommerce set it (certain), else the ONE matching post
     * the listener recorded; 0 when none is known or when more than one post matched (see ambiguous()).
     *
     * @param list<int> $recorded every matching post the listener saw during the save
     */
    public static function candidate( int $objectId, array $recorded ): int
    {
        if ( $objectId > 0 ) {
            return $objectId;
        }
        $ids = self::distinct( $recorded );

        return 1 === count( $ids ) ? $ids[0] : 0;
    }

    /**
     * More than one post matched and the object has no id: which one is ours cannot be told, so nothing may be deleted.
     *
     * @param list<int> $recorded
     */
    public static function ambiguous( int $objectId, array $recorded ): bool
    {
        return $objectId <= 0 && count( self::distinct( $recorded ) ) > 1;
    }

    /** @param list<int> $ids @return list<int> */
    private static function distinct( array $ids ): array
    {
        return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
    }
}
