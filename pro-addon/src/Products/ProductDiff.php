<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compares what was asked for with what a product reads back as, field by field, with the one set of equality rules update_products
 * and its undo share. Pure. Both sides are value shapes (see ProductFields): only the fields in `$expected` are compared.
 *
 * Rules: texts through ProductValuePolicy::sameText() (core stores a bare "&" as "&amp;" for a user without unfiltered_html); prices,
 * weight and dimensions as exact decimals ("20" equals "20.00"); an expected EMPTY category list accepts anything (WooCommerce files a
 * product without categories under its default one); stock_status is not compared when the expectation itself says stock is managed (a lone stock_status is always compared: the actual state may have been switched to managed since) and backorders are not compared
 * while it is not (WooCommerce works them out).
 */
final class ProductDiff
{
    private const TEXTS    = [ 'name', 'description', 'short_description' ];
    private const DECIMALS = [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ];

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     * @return list<string> the fields that differ ("sale_price", "variation 12 sale_price")
     */
    public static function compare( array $expected, array $actual ): array
    {
        $bad = self::fields( (array) ( $expected['fields'] ?? [] ), (array) ( $actual['fields'] ?? [] ), '' );
        foreach ( (array) ( $expected['variations'] ?? [] ) as $vid => $row ) {
            $have = $actual['variations'][ $vid ] ?? null;
            if ( ! is_array( $have ) ) {
                $bad[] = "variation {$vid}";
                continue;
            }
            $bad = array_merge( $bad, self::fields( (array) $row, $have, "variation {$vid} " ) );
        }

        return $bad;
    }

    /**
     * @param array<string,mixed> $e
     * @param array<string,mixed> $a
     * @return list<string>
     */
    private static function fields( array $e, array $a, string $prefix ): array
    {
        $bad    = [];
        $managedWanted = array_key_exists( 'manage_stock', $e ) && (bool) $e['manage_stock'];
        $manage        = array_key_exists( 'manage_stock', $e ) ? (bool) $e['manage_stock'] : (bool) ( $a['manage_stock'] ?? false );
        foreach ( $e as $field => $want ) {
            $got = $a[ $field ] ?? null;
            if ( in_array( $field, self::TEXTS, true ) ) {
                $same = is_string( $got ) && ProductValuePolicy::sameText( (string) $want, $got );
            } elseif ( in_array( $field, self::DECIMALS, true ) ) {
                $same = is_string( $got ) && self::sameDecimal( (string) $want, $got );
            } elseif ( 'stock_status' === $field && $managedWanted ) {
                $same = true;
            } elseif ( 'backorders' === $field && ! $manage ) {
                $same = true;
            } elseif ( 'category_ids' === $field ) {
                $same = [] === $want || self::sorted( $want ) === self::sorted( (array) $got );
            } elseif ( 'tag_ids' === $field ) {
                $same = self::sorted( (array) $want ) === self::sorted( (array) $got );
            } else {
                $same = $want === $got;
            }
            if ( ! $same ) {
                $bad[] = $prefix . $field;
            }
        }

        return $bad;
    }

    private static function sameDecimal( string $a, string $b ): bool
    {
        return '' === $a || '' === $b ? $a === $b : 0 === ProductValuePolicy::compareDecimals( $a, $b );
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function sorted( array $ids ): array
    {
        $ids = array_map( 'intval', array_values( $ids ) );
        sort( $ids );

        return $ids;
    }
}
