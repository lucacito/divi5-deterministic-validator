<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates the arguments of list_products for MCP (typed) and REST (query strings). A wrong value is refused, never cast
 * or clamped. The one REST allowance: digit strings for integers and "true"/"false" for the boolean (a query string has no types).
 */
final class ProductQuery
{
    public const STATUSES        = [ 'any', 'publish', 'draft', 'pending', 'private', 'future' ];
    public const TYPES           = [ 'simple', 'variable', 'grouped', 'external' ];
    public const STOCK_STATUSES  = [ 'instock', 'outofstock', 'onbackorder' ];
    public const ORDERBY         = [ 'title', 'date', 'price' ];
    public const ORDER           = [ 'asc', 'desc' ];
    public const MAX_PER_PAGE    = 50;
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PAGE        = 100000;

    /**
     * @param array<string,mixed> $in
     * @return array{args: ?array<string,mixed>, error: ?string}
     */
    public static function parse( array $in, bool $rest ): array
    {
        $out = [
            'status' => 'any', 'type' => null, 'category' => null, 'stock_status' => null, 'on_sale' => null,
            'search' => null, 'orderby' => 'title', 'order' => 'asc', 'page' => 1, 'per_page' => self::DEFAULT_PER_PAGE,
        ];

        $enums = [ 'status' => self::STATUSES, 'type' => self::TYPES, 'stock_status' => self::STOCK_STATUSES, 'orderby' => self::ORDERBY, 'order' => self::ORDER ];
        foreach ( $enums as $key => $allowed ) {
            $v = $in[ $key ] ?? null;
            if ( null === $v || ( $rest && '' === $v ) ) {
                continue;
            }
            if ( ! is_string( $v ) || ! in_array( $v, $allowed, true ) ) {
                return self::fail( "\"{$key}\" must be one of: " . implode( ', ', $allowed ) . '.' );
            }
            $out[ $key ] = $v;
        }

        $category = $in['category'] ?? null;
        if ( null !== $category && ! ( $rest && '' === $category ) ) {
            if ( ! is_string( $category ) || 1 !== preg_match( '/^[A-Za-z0-9%_-]{1,200}$/', $category ) ) {
                return self::fail( '"category" must be a category slug (letters, digits, "-", "_" or "%", at most 200 characters).' );
            }
            $out['category'] = $category;
        }

        $search = $in['search'] ?? null;
        if ( null !== $search ) {
            if ( ! is_string( $search ) || mb_strlen( trim( $search ) ) > 100 ) {
                return self::fail( '"search" must be a string of at most 100 characters.' );
            }
            $search = trim( $search );
            if ( '' !== $search ) {
                $out['search'] = $search;
            }
        }

        $onSale = $in['on_sale'] ?? null;
        if ( null !== $onSale && ! ( $rest && '' === $onSale ) ) {
            if ( is_bool( $onSale ) ) {
                $out['on_sale'] = $onSale;
            } elseif ( $rest && 'true' === $onSale ) {
                $out['on_sale'] = true;
            } elseif ( $rest && 'false' === $onSale ) {
                $out['on_sale'] = false;
            } else {
                return self::fail( '"on_sale" must be true or false.' );
            }
        }

        foreach ( [ 'page' => [ 1, self::MAX_PAGE ], 'per_page' => [ 1, self::MAX_PER_PAGE ] ] as $key => [ $min, $max ] ) {
            $v = $in[ $key ] ?? null;
            if ( null === $v || ( $rest && '' === $v ) ) {
                continue;
            }
            if ( $rest && is_string( $v ) && 1 === preg_match( '/^\d{1,6}$/', $v ) ) {
                $v = (int) $v;
            }
            if ( ! is_int( $v ) || $v < $min || $v > $max ) {
                return self::fail( "\"{$key}\" must be a whole number from {$min} to {$max}." );
            }
            $out[ $key ] = $v;
        }

        return [ 'args' => $out, 'error' => null ];
    }

    /** @return array{args: null, error: string} */
    private static function fail( string $message ): array
    {
        return [ 'args' => null, 'error' => $message ];
    }
}
