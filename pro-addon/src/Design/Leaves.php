<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Leaf-by-leaf comparison of decoded block attributes with a resolved patch (spec 2026-10-25-pro-design-switch-design.md
 * section 5.2). Attributes are decoded the free way (`BlockAttrs::decode`: objects are `\stdClass`, lists are arrays).
 *
 * A LEAF is a value that is not a non-empty object: a scalar, a list (one leaf, compared whole, as the free merge
 * replaces lists whole) or an empty object. Two leaves agree when their JSON encodings are equal. Pure and deterministic:
 * property order is the decoded order, never a hash order.
 */
final class Leaves
{
    /** The flags every leaf comparison encodes with (the same for both sides, so only the value decides). */
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Every leaf of $n in document order, as [path segments, value]. An empty $n has no leaves.
     *
     * @return list<array{0:list<string>,1:mixed}>
     */
    public static function of( \stdClass $n ): array
    {
        $out = [];
        self::walk( $n, [], $out );

        return $out;
    }

    /**
     * How many leaves of $patch are present in $attrs with the same JSON value, or -1 when one is missing or differs.
     * An empty patch gives 0.
     */
    public static function count( \stdClass $attrs, \stdClass $patch ): int
    {
        $n = 0;
        foreach ( self::of( $patch ) as [ $path, $value ] ) {
            if ( ! self::agrees( $attrs, $path, $value ) ) {
                return -1;
            }
            ++$n;
        }

        return $n;
    }

    /** How many leaves of $patch are present in $attrs with the same JSON value (the others missing or different). */
    public static function agreeing( \stdClass $attrs, \stdClass $patch ): int
    {
        $n = 0;
        foreach ( self::of( $patch ) as [ $path, $value ] ) {
            if ( self::agrees( $attrs, $path, $value ) ) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * The dotted paths of the leaves of $patch that $attrs lacks or holds with another value, in patch order.
     *
     * @return list<string>
     */
    public static function differing( \stdClass $attrs, \stdClass $patch ): array
    {
        $out = [];
        foreach ( self::of( $patch ) as [ $path, $value ] ) {
            if ( ! self::agrees( $attrs, $path, $value ) ) {
                $out[] = implode( '.', $path );
            }
        }

        return $out;
    }

    /** Does $attrs hold anything (any value) at one of the leaf paths of $patch? */
    public static function touches( \stdClass $attrs, \stdClass $patch ): bool
    {
        foreach ( self::of( $patch ) as [ $path ] ) {
            if ( self::lookup( $attrs, $path )[0] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove every leaf path of $patch from $attrs (whatever the value there), then prune, along each removed path only,
     * every object the removal left empty. Objects that were empty before, or that lie off the removed paths, survive.
     */
    public static function strip( \stdClass $attrs, \stdClass $patch ): void
    {
        foreach ( self::of( $patch ) as [ $path ] ) {
            $chain = [ $attrs ];
            $node  = $attrs;
            $last  = count( $path ) - 1;
            for ( $i = 0; $i < $last; $i++ ) {
                $next = self::child( $node, $path[ $i ] );
                if ( ! $next instanceof \stdClass ) {
                    continue 2; // the path is not there: nothing to remove
                }
                $node    = $next;
                $chain[] = $node;
            }
            if ( ! property_exists( $node, $path[ $last ] ) ) {
                continue;
            }
            unset( $node->{ $path[ $last ] } );
            for ( $i = $last; $i >= 1; $i-- ) {
                if ( [] !== get_object_vars( $chain[ $i ] ) ) {
                    break;
                }
                unset( $chain[ $i - 1 ]->{ $path[ $i - 1 ] } );
            }
        }
    }

    /** The JSON text a leaf comparison uses for $value. */
    public static function encode( mixed $value ): string
    {
        return (string) json_encode( $value, self::FLAGS );
    }

    /**
     * @param list<string>                              $path
     * @param list<array{0:list<string>,1:mixed}> $out
     */
    private static function walk( \stdClass $n, array $path, array &$out ): void
    {
        foreach ( get_object_vars( $n ) as $key => $value ) {
            $p   = $path;
            $p[] = (string) $key;
            if ( $value instanceof \stdClass && [] !== get_object_vars( $value ) ) {
                self::walk( $value, $p, $out );
                continue;
            }
            $out[] = [ $p, $value ];
        }
    }

    /** @param list<string> $path */
    private static function agrees( \stdClass $attrs, array $path, mixed $value ): bool
    {
        [ $found, $got ] = self::lookup( $attrs, $path );

        return $found && self::encode( $got ) === self::encode( $value );
    }

    /**
     * @param list<string> $path
     *
     * @return array{0:bool,1:mixed}
     */
    private static function lookup( \stdClass $attrs, array $path ): array
    {
        $node = $attrs;
        foreach ( $path as $key ) {
            if ( ! $node instanceof \stdClass || ! property_exists( $node, $key ) ) {
                return [ false, null ];
            }
            $node = $node->{$key};
        }

        return [ true, $node ];
    }

    private static function child( \stdClass $node, string $key ): mixed
    {
        return property_exists( $node, $key ) ? $node->{$key} : null;
    }
}
