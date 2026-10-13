<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The inverse of an undone `product_created`: the product is in the trash. Undoing it takes the product out of the trash (core brings
 * it back as a DRAFT) and records a `product_created` item, so an undo can always be undone.
 */
final class ProductTrashedKind implements ItemKind
{
    public const NAME = 'product_trashed';

    public function __construct( private ?ProductWriter $writer = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function current( array $item ): mixed
    {
        $s = $this->writer()->snapshot( (int) $item['ref'] );

        return null !== $s ? (string) $s['status'] : null;
    }

    public function allowed( array $item ): bool
    {
        $id = (int) $item['ref'];

        return current_user_can( 'edit_post', $id ) && current_user_can( 'delete_post', $id );
    }

    public function restore( array $item ): array
    {
        $id = (int) $item['ref'];
        if ( null === $this->writer()->snapshot( $id ) ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        if ( ! wp_untrash_post( $id ) ) {
            return [ 'ok' => false, 'reason' => 'untrash_failed' ];
        }
        $s = $this->writer()->snapshot( $id );
        if ( null === $s ) {
            // Untrashed, but gone again before it could be read: nothing to take away a second time.
            return [ 'ok' => true, 'inverse' => Item::make( ProductCreatedKind::NAME, (string) $id, (string) $item['label'], null, null, [ 'available' => false, 'reason' => 'missing' ] ) ];
        }

        return [ 'ok' => true, 'inverse' => ProductCreatedKind::itemFor( $id, $s, (string) $s['name'] ) ];
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
