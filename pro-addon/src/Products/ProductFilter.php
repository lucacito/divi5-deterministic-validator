<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The arguments of bulk_adjust_prices (`filter`, `change`, `round_to`), validated and normalised. Pure: no WordPress call. A wrong type is
 * refused with a reason, never cast; every reason is returned at once. The normalised spec is what the preview token is bound to, so
 * the same request written another way (keys in another order, a list in another order, "10.0" for "10") gives the same token.
 */
final class ProductFilter
{
    public const MAX_CATEGORIES = 10;
    public const MAX_TAGS       = 20;
    public const MAX_IDS        = 100;
    public const TYPES          = [ 'simple', 'variable', 'external' ];
    public const STATUSES       = [ 'publish', 'private', 'draft', 'pending' ];
    public const STOCK_STATUSES = [ 'instock', 'outofstock', 'onbackorder' ];
    public const MODES          = [ 'percent', 'amount' ];

    private const FILTER_KEYS = [ 'categories', 'tags', 'types', 'stock_status', 'on_sale', 'search', 'ids', 'all', 'exclude_ids', 'status' ];
    private const SLUG        = '/^[A-Za-z0-9%_-]{1,200}$/D';

    /**
     * @param array<string,mixed> $arguments the tool arguments (only `filter`, `change` and `round_to` are read)
     * @return array{spec: ?array{filter: array<string,mixed>, change: array{mode:string, value:string}, round_to: ?string}, reasons: list<string>}
     */
    public static function parse( array $arguments ): array
    {
        $r      = [];
        $filter = self::filter( $arguments['filter'] ?? null, $r );
        $change = self::change( $arguments['change'] ?? null, $r );
        $round  = self::roundTo( $arguments['round_to'] ?? null, $r );

        return [ 'spec' => [] === $r ? [ 'filter' => $filter, 'change' => $change, 'round_to' => $round ] : null, 'reasons' => $r ];
    }

    /**
     * @param list<string> $r
     * @return array<string,mixed>
     */
    private static function filter( mixed $in, array &$r ): array
    {
        $out = [
            'categories' => [], 'tags' => [], 'types' => [], 'stock_status' => null, 'on_sale' => null, 'search' => null,
            'ids' => [], 'all' => false, 'exclude_ids' => [], 'status' => [ 'publish' ],
        ];
        if ( ! is_array( $in ) || array_is_list( $in ) ) {
            $r[] = '"filter" is required and must be an object';

            return $out;
        }
        foreach ( array_keys( $in ) as $key ) {
            if ( ! in_array( $key, self::FILTER_KEYS, true ) ) {
                $r[] = '"filter.' . self::shown( (string) $key ) . '" is not a supported filter';
            }
        }
        $out['categories'] = self::slugs( $in, 'categories', self::MAX_CATEGORIES, $r );
        $out['tags']       = self::slugs( $in, 'tags', self::MAX_TAGS, $r );
        $out['types']      = self::enumList( $in, 'types', self::TYPES, false, $r );
        $out['ids']        = self::idList( $in, 'ids', $r );
        $out['exclude_ids'] = self::idList( $in, 'exclude_ids', $r );
        if ( self::given( $in, 'status' ) ) {
            $status = self::enumList( $in, 'status', self::STATUSES, true, $r );
            if ( [] !== $status ) {
                $out['status'] = $status;
            }
        }
        if ( self::given( $in, 'stock_status' ) ) {
            if ( is_string( $in['stock_status'] ) && in_array( $in['stock_status'], self::STOCK_STATUSES, true ) ) {
                $out['stock_status'] = $in['stock_status'];
            } else {
                $r[] = '"filter.stock_status" must be one of: ' . implode( ', ', self::STOCK_STATUSES );
            }
        }
        if ( self::given( $in, 'on_sale' ) ) {
            if ( is_bool( $in['on_sale'] ) ) {
                $out['on_sale'] = $in['on_sale'];
            } else {
                $r[] = '"filter.on_sale" must be true or false';
            }
        }
        if ( self::given( $in, 'search' ) ) {
            $search = $in['search'];
            if ( ! is_string( $search ) || '' === trim( $search ) || mb_strlen( trim( $search ) ) > 100 || 1 !== preg_match( '//u', $search ) ) {
                $r[] = '"filter.search" must be a non-empty string of at most 100 characters';
            } else {
                $out['search'] = trim( $search );
            }
        }
        if ( self::given( $in, 'all' ) ) {
            if ( true === $in['all'] ) {
                $out['all'] = true;
            } elseif ( false !== $in['all'] ) {
                $r[] = '"filter.all" must be true or false';
            }
        }

        $selects = [] !== $out['categories'] || [] !== $out['tags'] || [] !== $out['types'] || null !== $out['stock_status'] || null !== $out['on_sale']
            || null !== $out['search'] || [] !== $out['ids'];
        if ( $out['all'] && $selects ) {
            $r[] = '"filter.all" cannot be combined with other filters; use either "all": true or the narrowing filters';
        } elseif ( ! $out['all'] && ! $selects && [] === $r ) {
            $r[] = '"filter" must narrow the products (categories, tags, types, stock_status, on_sale, search or ids), or say "all": true to adjust every product';
        }

        return $out;
    }

    /**
     * @param list<string> $r
     * @return array{mode: string, value: string}
     */
    private static function change( mixed $in, array &$r ): array
    {
        $none = [ 'mode' => 'percent', 'value' => '0' ];
        if ( ! is_array( $in ) || array_is_list( $in ) ) {
            $r[] = '"change" is required and must be an object: { "mode": "percent" or "amount", "value": "-10" }';

            return $none;
        }
        foreach ( array_keys( $in ) as $key ) {
            if ( 'mode' !== $key && 'value' !== $key ) {
                $r[] = '"change.' . self::shown( (string) $key ) . '" is not supported (only "mode" and "value")';
            }
        }
        $mode = $in['mode'] ?? null;
        if ( ! is_string( $mode ) || ! in_array( $mode, self::MODES, true ) ) {
            $r[] = '"change.mode" must be "percent" or "amount"';

            return $none;
        }
        $value = $in['value'] ?? null;
        $regex = 'percent' === $mode ? '/^-?\d{1,3}(\.\d{1,2})?$/D' : '/^-?\d{1,6}(\.\d{1,6})?$/D';
        if ( ! is_string( $value ) || 1 !== preg_match( $regex, $value ) ) {
            $r[] = 'percent' === $mode
                ? '"change.value" must be a signed decimal string with at most 2 decimals, for example "10" or "-12.5" (no % sign, not a number)'
                : '"change.value" must be a signed decimal string with at most 6 decimals, for example "-5" or "2.50" (no currency symbol, not a number)';

            return [ 'mode' => $mode, 'value' => '0' ];
        }
        $units = PriceAdjuster::units( $value );
        if ( 0 === $units ) {
            $r[] = '"change.value" must not be zero';
        } elseif ( 'percent' === $mode && ( $units < -90 * PriceAdjuster::SCALE || $units > 500 * PriceAdjuster::SCALE ) ) {
            $r[] = '"change.value" for a percent must be from -90 to 500';
        } elseif ( 'amount' === $mode && abs( $units ) > 100000 * PriceAdjuster::SCALE ) {
            $r[] = '"change.value" for an amount must be at most 100000 either way';
        }

        return [ 'mode' => $mode, 'value' => self::canonical( $units ) ];
    }

    /** @param list<string> $r */
    private static function roundTo( mixed $in, array &$r ): ?string
    {
        if ( null === $in ) {
            return null;
        }
        if ( ! is_string( $in ) || 1 !== preg_match( '/^(?:[01]|0\.\d{1,2})$/D', $in ) ) {
            $r[] = '"round_to" must be a string such as "0.95", "0.75" or "0.5" (what the new price should end in), or "0" for whole numbers';

            return null;
        }

        return '1' === $in ? '0' : self::canonical( PriceAdjuster::units( $in ) );
    }

    /** Millionths as the shortest decimal string ("10", "-12.5", "0.95"). */
    private static function canonical( int $units ): string
    {
        $neg  = $units < 0;
        $abs  = abs( $units );
        $int  = intdiv( $abs, PriceAdjuster::SCALE );
        $frac = rtrim( str_pad( (string) ( $abs % PriceAdjuster::SCALE ), 6, '0', STR_PAD_LEFT ), '0' );

        return ( $neg ? '-' : '' ) . $int . ( '' === $frac ? '' : '.' . $frac );
    }

    /** @param array<string,mixed> $in */
    private static function given( array $in, string $key ): bool
    {
        return array_key_exists( $key, $in ) && null !== $in[ $key ];
    }

    /**
     * @param array<string,mixed> $in
     * @param list<string> $r
     * @return list<string>
     */
    private static function slugs( array $in, string $key, int $max, array &$r ): array
    {
        if ( ! self::given( $in, $key ) ) {
            return [];
        }
        $list = $in[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list || count( $list ) > $max ) {
            $r[] = "\"filter.{$key}\" must be a list of 1 to {$max} category or tag slugs";

            return [];
        }
        foreach ( $list as $slug ) {
            if ( ! is_string( $slug ) || 1 !== preg_match( self::SLUG, $slug ) ) {
                $r[] = "\"filter.{$key}\" must contain only slugs (letters, digits, \"-\", \"_\" or \"%\")";

                return [];
            }
        }
        $list = array_values( array_unique( $list ) );
        sort( $list, SORT_STRING );

        return $list;
    }

    /**
     * @param array<string,mixed> $in
     * @param list<string> $allowed
     * @param list<string> $r
     * @return list<string>
     */
    private static function enumList( array $in, string $key, array $allowed, bool $required, array &$r ): array
    {
        if ( ! self::given( $in, $key ) ) {
            return [];
        }
        $list = $in[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list ) {
            $r[] = "\"filter.{$key}\" must be a list of: " . implode( ', ', $allowed );

            return [];
        }
        foreach ( $list as $v ) {
            if ( ! is_string( $v ) || ! in_array( $v, $allowed, true ) ) {
                $r[] = "\"filter.{$key}\" must be a list of: " . implode( ', ', $allowed );

                return [];
            }
        }
        $list = array_values( array_unique( $list ) );
        sort( $list );

        return $list;
    }

    /**
     * @param array<string,mixed> $in
     * @param list<string> $r
     * @return list<int>
     */
    private static function idList( array $in, string $key, array &$r ): array
    {
        if ( ! self::given( $in, $key ) ) {
            return [];
        }
        $list = $in[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list || count( $list ) > self::MAX_IDS ) {
            $r[] = "\"filter.{$key}\" must be a list of 1 to " . self::MAX_IDS . ' product ids';

            return [];
        }
        foreach ( $list as $id ) {
            if ( ! is_int( $id ) || $id < 1 ) {
                $r[] = "\"filter.{$key}\" must contain only whole product ids from 1";

                return [];
            }
        }
        $list = array_values( array_unique( $list ) );
        sort( $list );

        return $list;
    }

    private static function shown( string $key ): string
    {
        return mb_strlen( $key ) > 80 ? mb_substr( $key, 0, 80 ) . '...' : $key;
    }
}
