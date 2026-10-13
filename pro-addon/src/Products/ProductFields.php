<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The vocabulary of update_products, and how a change set becomes the "shape" the batch kind stores and compares. Pure.
 *
 * A change set (what ProductWriter::apply() takes) is a flat map of product fields plus, for a variable product, `variations`
 * (variation id => the row fields that change). A SHAPE is the same data split in two: `['fields' => [...], 'variations' => [id => [...]]]`.
 * A shape holding VALUES is what the batch item stores as `before` and hashes as `after`; a shape holding `true` for every
 * field is a key shape that says which fields to read back (`tracked()`, `pick()`).
 */
final class ProductFields
{
    /** The stock group. When manage_stock changes, all four are recorded so an undo also puts back the values WooCommerce derives. */
    public const STOCK = [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ];

    /** What one variation row (and the price and stock part of a product) holds. */
    public const ROW = [ 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ];

    /** The text fields whose previous value is stored for undo (size and encoding are checked). */
    public const TEXTS = [ 'name', 'description', 'short_description' ];

    /**
     * @param array<string,mixed> $changes
     * @return array{fields: array<string,mixed>, variations: array<int,array<string,mixed>>}
     */
    public static function asShape( array $changes ): array
    {
        $variations = [];
        foreach ( (array) ( $changes['variations'] ?? [] ) as $vid => $row ) {
            $variations[ (int) $vid ] = (array) $row;
        }
        unset( $changes['variations'] );

        return [ 'fields' => $changes, 'variations' => $variations ];
    }

    /**
     * @param array<string,mixed> $shape
     * @return array<string,mixed>
     */
    public static function asChanges( array $shape ): array
    {
        $changes = (array) ( $shape['fields'] ?? [] );
        $rows    = (array) ( $shape['variations'] ?? [] );
        if ( [] !== $rows ) {
            $changes['variations'] = $rows;
        }

        return $changes;
    }

    /**
     * The fields a change set touches: the changed ones, plus the whole stock group wherever manage_stock changes.
     *
     * @param array<string,mixed> $changes
     * @return array{fields: array<string,true>, variations: array<int,array<string,true>>}
     */
    public static function tracked( array $changes ): array
    {
        $shape = self::asShape( $changes );
        $out   = [ 'fields' => self::keys( array_keys( $shape['fields'] ) ), 'variations' => [] ];
        foreach ( $shape['variations'] as $vid => $row ) {
            $out['variations'][ $vid ] = self::keys( array_keys( $row ) );
        }

        return $out;
    }

    /**
     * The values of the fields a shape names, read from a ProductWriter::product() state. A field the state does not have is null;
     * a variation row the state does not have is left out (so a comparison reports it).
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $shape any shape: only its keys are used
     * @return array{fields: array<string,mixed>, variations: array<int,array<string,mixed>>}
     */
    public static function pick( array $state, array $shape ): array
    {
        $out = [ 'fields' => [], 'variations' => [] ];
        foreach ( array_keys( (array) ( $shape['fields'] ?? [] ) ) as $field ) {
            $out['fields'][ $field ] = $state[ $field ] ?? null;
        }
        foreach ( (array) ( $shape['variations'] ?? [] ) as $vid => $row ) {
            $have = $state['variations'][ (int) $vid ] ?? null;
            if ( ! is_array( $have ) ) {
                continue;
            }
            foreach ( array_keys( (array) $row ) as $field ) {
                $out['variations'][ (int) $vid ][ $field ] = $have[ $field ] ?? null;
            }
        }

        return $out;
    }

    /**
     * @param list<string|int> $fields
     * @return array<string,true>
     */
    private static function keys( array $fields ): array
    {
        $out = [];
        foreach ( $fields as $field ) {
            $out[ (string) $field ] = true;
        }
        if ( isset( $out['manage_stock'] ) ) {
            foreach ( self::STOCK as $f ) {
                $out[ $f ] = true;
            }
        }

        return $out;
    }
}
