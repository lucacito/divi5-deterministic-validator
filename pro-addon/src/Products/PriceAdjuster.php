<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The arithmetic of bulk_adjust_prices, exact and pure: no floats, no BCMath, no WordPress. A price is a decimal string with at most
 * 10 integer and 6 decimal digits; internally it is a whole number of millionths, which fits a PHP int (10 digits + 6 = 16 digits). A
 * percent factor would overflow that, so a price is split (price = q * 10000 + r) before it is multiplied.
 *
 * It trusts that `$mode` / `$value` / `$roundTo` were validated by ProductFilter.
 */
final class PriceAdjuster
{
    public const SCALE   = 1000000;
    public const MAX_INT = 9999999999;

    /** The reason codes of a price that cannot be adjusted. */
    public const REASONS = [
        'no_regular_price'  => 'the product has no regular price',
        'not_positive'      => 'the new price would be zero or below',
        'too_large'         => 'the new price would be above the largest price WooCommerce can hold here',
        'rounding_reversed' => 'rounding would move the price the opposite way to the change',
        'not_above_sale'    => 'the new regular price would not stay above the stored sale price (active or scheduled)',
    ];

    /**
     * @param string $old the regular price now ("" for none)
     * @param string $sale the sale price now ("" for none)
     * @param string $mode "percent" or "amount"
     * @param string $value signed decimal string, for example "10", "-12.5", "-5"
     * @param int $decimals the shop's number of price decimals (0 to 6)
     * @param ?string $roundTo "0.95" and the like, or null
     * @return array{price: ?string, reason: ?string} the new price (a decimal string with `$decimals` decimals, or more when `$roundTo` has more) or the reason code
     */
    public static function adjust( string $old, string $sale, string $mode, string $value, int $decimals, ?string $roundTo ): array
    {
        if ( '' === $old ) {
            return self::no( 'no_regular_price' );
        }
        $decimals = max( 0, min( 6, $decimals ) );
        $oldUnits = self::units( $old );

        // The exact result as whole millionths ($whole) plus a fraction of a millionth ($rem / 10000).
        if ( 'percent' === $mode ) {
            $factor = 10000 + intdiv( self::units( $value ), 10000 ); // at most 2 decimals, so this is an exact count of hundredths of a percent
            $q      = intdiv( $oldUnits, 10000 );
            $r      = $oldUnits % 10000;
            $whole  = $q * $factor + intdiv( $r * $factor, 10000 );
            $rem    = ( $r * $factor ) % 10000;
        } else {
            $whole = $oldUnits + self::units( $value );
            $rem   = 0;
        }
        if ( $whole < 0 || ( 0 === $whole && 0 === $rem ) ) {
            return self::no( 'not_positive' );
        }

        if ( null !== $roundTo ) {
            // The nearest price that ends in $roundTo (ties up): n + r for a whole number n.
            $r     = self::units( $roundTo );
            $n     = self::floorDiv( $whole - $r + intdiv( self::SCALE, 2 ), self::SCALE );
            $final = $n * self::SCALE + $r;
            $shown = max( $decimals, self::decimalsOf( $roundTo ) );
        } else {
            $step  = (int) ( 10 ** ( 6 - $decimals ) );
            $k     = intdiv( $whole, $step );
            $rm    = $whole % $step;
            $up    = ( $rm * 2 * 10000 + $rem * 2 ) >= $step * 10000 ? 1 : 0;
            $final = ( $k + $up ) * $step;
            $shown = $decimals;
        }

        if ( $final <= 0 ) {
            return self::no( 'not_positive' );
        }
        if ( $final > self::MAX_INT * self::SCALE + ( self::SCALE - 1 ) ) {
            return self::no( 'too_large' );
        }
        // Rounding must not undo the change: a rise may not end below the old price, nor a cut above it.
        $rise = '-' !== substr( $value, 0, 1 );
        if ( ( $rise && $final < $oldUnits ) || ( ! $rise && $final > $oldUnits ) ) {
            return self::no( 'rounding_reversed' );
        }
        if ( '' !== $sale && $final <= self::units( $sale ) ) {
            return self::no( 'not_above_sale' );
        }

        return [ 'price' => self::text( $final, $shown ), 'reason' => null ];
    }

    /** Whether two decimal strings hold the same number. */
    public static function same( string $a, string $b ): bool
    {
        return '' !== $a && '' !== $b && self::units( $a ) === self::units( $b );
    }

    /** A decimal string such as "-12.5" or "20.95" as whole millionths. */
    public static function units( string $decimal ): int
    {
        $neg = '-' === substr( $decimal, 0, 1 );
        $d   = ltrim( $decimal, '+-' );
        [ $int, $frac ] = array_pad( explode( '.', $d, 2 ), 2, '' );
        $frac = substr( str_pad( $frac, 6, '0' ), 0, 6 );
        $n    = (int) $int * self::SCALE + (int) $frac;

        return $neg ? -$n : $n;
    }

    /** Whole millionths as a decimal string with exactly $decimals decimals (the value is already a multiple of the step). */
    private static function text( int $units, int $decimals ): string
    {
        $int  = intdiv( $units, self::SCALE );
        $frac = str_pad( (string) ( $units % self::SCALE ), 6, '0', STR_PAD_LEFT );

        return 0 === $decimals ? (string) $int : $int . '.' . substr( $frac, 0, $decimals );
    }

    private static function decimalsOf( string $decimal ): int
    {
        $pos = strpos( $decimal, '.' );

        return false === $pos ? 0 : strlen( rtrim( substr( $decimal, $pos + 1 ), '0' ) );
    }

    private static function floorDiv( int $a, int $b ): int
    {
        $q = intdiv( $a, $b );

        return ( $a % $b < 0 ) ? $q - 1 : $q;
    }

    /** @return array{price: null, reason: string} */
    private static function no( string $reason ): array
    {
        return [ 'price' => null, 'reason' => $reason ];
    }
}
