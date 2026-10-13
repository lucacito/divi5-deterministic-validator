<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Products\ProductDiff;
use AiEditorDivi5\Pro\Products\ProductFields;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What update_products changed on one product. `ref` is the product id. `before` is a shape (see ProductFields) holding the PREVIOUS values
 * of only the fields the run changed, except that wherever manage_stock changes (on the product OR on a variation row) the whole stock
 * group is recorded (manage_stock, stock_quantity, stock_status, backorders, low_stock_amount); `after_hash` is the hash of those same fields as read back after the save. Undo puts the previous values back, but only
 * while those fields still hold what the run wrote (the changed-since rule); `force` skips that rule, never the permission or the
 * existence checks. After a restore the fields are read back and compared; the inverse item records the values the restore replaced, so
 * the undo can itself be undone.
 */
final class ProductFieldsKind implements ItemKind
{
    public const NAME = 'product_fields';

    public function __construct( private ?ProductWriter $writer = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $before value shape: the previous values
     * @param array<string,mixed> $after value shape: the same fields as read back after the save
     * @return array<string,mixed>
     */
    public static function itemFor( int $id, string $name, string $what, array $before, array $after ): array
    {
        return Item::make( self::NAME, (string) $id, "Updated product \"{$name}\" (#{$id}): {$what}", $before, $after );
    }

    public function current( array $item ): mixed
    {
        $state = $this->writer()->product( (int) $item['ref'] );

        return null === $state ? null : ProductFields::pick( $state, self::shape( $item ) );
    }

    public function allowed( array $item ): bool
    {
        $id = (int) $item['ref'];
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return false;
        }
        $fields = array_keys( self::shape( $item )['fields'] );

        return [] === array_intersect( $fields, [ 'category_ids', 'tag_ids' ] ) || current_user_can( 'assign_product_terms' );
    }

    public function restore( array $item ): array
    {
        $id     = (int) $item['ref'];
        $writer = $this->writer();
        $before = self::shape( $item );
        $state  = $writer->product( $id );
        if ( null === $state ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        foreach ( array_keys( $before['variations'] ) as $vid ) {
            if ( ! isset( $state['variations'][ $vid ] ) ) {
                return [ 'ok' => false, 'reason' => 'variation_missing' ];
            }
        }
        $now = ProductFields::pick( $state, $before );
        try {
            $writer->apply( $id, ProductFields::asChanges( $before ) );
            $back = $writer->product( $id );
        } catch ( \Throwable $e ) {
            $this->putBack( $id, $now );

            return [ 'ok' => false, 'reason' => 'save_failed' ];
        }
        // Compare against the whole read-back state: the picked fields alone would hide manage_stock, and ProductDiff needs it to judge backorders.
        $after = null === $back ? null : ProductFields::pick( $back, $before );
        if ( null === $back || [] !== ProductDiff::compare( $before, ProductFields::asShape( $back ) ) ) {
            $this->putBack( $id, $now );

            return [ 'ok' => false, 'reason' => 'restore_differs' ];
        }

        return [ 'ok' => true, 'inverse' => Item::make( self::NAME, (string) $id, (string) $item['label'], $now, $after ) ];
    }

    /** Best effort after a failed restore: the values the product held just before it. */
    private function putBack( int $id, array $now ): void
    {
        try {
            $this->writer()->apply( $id, ProductFields::asChanges( $now ) );
        } catch ( \Throwable $e ) {
            // nothing more can be done; the undo reports the item as failed
        }
    }

    /**
     * @param array<string,mixed> $item
     * @return array{fields: array<string,mixed>, variations: array<int,array<string,mixed>>}
     */
    private static function shape( array $item ): array
    {
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : [];
        $rows   = [];
        foreach ( (array) ( $before['variations'] ?? [] ) as $vid => $row ) {
            $rows[ (int) $vid ] = (array) $row;
        }

        return [ 'fields' => (array) ( $before['fields'] ?? [] ), 'variations' => $rows ];
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
