<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A product create_product made. `ref` is the product id, `before` is null, `after_hash` is a hash of the FIELDS read back after the
 * save. Undo trashes the product, but only while it is still a DRAFT and still matches that hash (the changed-since rule), so a product
 * the owner edited, published or made private is skipped and reported, never trashed behind their back. With the trash disabled
 * (EMPTY_TRASH_DAYS 0) a trash is a permanent delete, so a changed product is never removed, forced or not (the same guard as
 * PageCreatedKind, whose trashEnabled() rule is reused). The inverse is a `product_trashed` item.
 */
final class ProductCreatedKind implements ItemKind
{
    public const NAME = 'product_created';

    /** What the undo compares: EVERY read-back field except `status` (current() checks the status on its own), so any edit is seen. */
    public const FIELDS = [
        'name', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status',
        'backorders', 'category_ids', 'tag_ids', 'image_id', 'gallery_image_ids', 'weight', 'length', 'width', 'height', 'tax_status',
        'virtual', 'sold_individually',
    ];

    public function __construct( private ?ProductWriter $writer = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function hashed( array $snapshot ): array
    {
        $out = [];
        foreach ( self::FIELDS as $field ) {
            $out[ $field ] = $snapshot[ $field ] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function itemFor( int $id, array $snapshot, string $name ): array
    {
        return Item::make( self::NAME, (string) $id, "Created product \"{$name}\" (#{$id})", null, self::hashed( $snapshot ) );
    }

    public function current( array $item ): mixed
    {
        $s = $this->writer()->snapshot( (int) $item['ref'] );

        return null !== $s && 'draft' === $s['status'] ? self::hashed( $s ) : null;
    }

    public function allowed( array $item ): bool
    {
        $id = (int) $item['ref'];

        return current_user_can( 'edit_post', $id ) && current_user_can( 'delete_post', $id );
    }

    public function restore( array $item ): array
    {
        $id = (int) $item['ref'];
        $s  = $this->writer()->snapshot( $id );
        if ( null === $s ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        // `force` skips the changed-since rule, never this one: a product that is live (or private, or pending) is not the draft the call left.
        if ( 'draft' !== $s['status'] ) {
            return [ 'ok' => false, 'reason' => 'not_draft' ];
        }
        if ( ! PageCreatedKind::trashEnabled() && Canon::hash( self::hashed( $s ) ) !== (string) ( $item['after_hash'] ?? '' ) ) {
            return [ 'ok' => false, 'reason' => 'would_delete_permanently' ];
        }
        if ( ! wp_trash_post( $id ) ) {
            return [ 'ok' => false, 'reason' => 'trash_failed' ];
        }
        $undo = PageCreatedKind::trashEnabled() ? null : [ 'available' => false, 'reason' => 'trash_disabled' ];

        return [ 'ok' => true, 'inverse' => Item::make( ProductTrashedKind::NAME, (string) $id, (string) $item['label'], null, 'trash', $undo ) ];
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
