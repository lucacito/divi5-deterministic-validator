<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Strict argument readers for Pro tools. A value of the wrong type is rejected with a message that is safe to
 * show the client; nothing is ever cast.
 */
final class Args
{
    /** @param array<string,mixed> $a */
    public static function int( array $a, string $key, bool $required = true, int $min = 1 ): ?int
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            return self::missing( $key, $required );
        }
        if ( ! is_int( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be an integer." );
        }
        if ( $a[ $key ] < $min ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be at least {$min}." );
        }

        return $a[ $key ];
    }

    /** @param array<string,mixed> $a */
    public static function string( array $a, string $key, bool $required = true, int $maxBytes = 10000 ): ?string
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            return self::missing( $key, $required );
        }
        if ( ! is_string( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be a string." );
        }
        if ( $required && '' === trim( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must not be empty." );
        }
        if ( strlen( $a[ $key ] ) > $maxBytes ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be at most {$maxBytes} bytes." );
        }

        return $a[ $key ];
    }

    /** @param array<string,mixed> $a */
    public static function bool( array $a, string $key ): bool
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            return false;
        }
        if ( ! is_bool( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be true or false." );
        }

        return $a[ $key ];
    }

    /**
     * @param array<string,mixed> $a
     * @return list<mixed>
     */
    public static function listOf( array $a, string $key, int $maxItems ): array
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            self::missing( $key, true );
        }
        if ( ! is_array( $a[ $key ] ) || ! array_is_list( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be a list." );
        }
        if ( [] === $a[ $key ] ) {
            throw new \InvalidArgumentException( "\"{$key}\" must not be empty." );
        }
        if ( count( $a[ $key ] ) > $maxItems ) {
            throw new \InvalidArgumentException( "\"{$key}\" must have at most {$maxItems} items." );
        }

        return $a[ $key ];
    }

    /** The add-on supports PHP 8.1, so no standalone `null` return type (8.2+). */
    private static function missing( string $key, bool $required ): mixed
    {
        if ( $required ) {
            throw new \InvalidArgumentException( "\"{$key}\" is required." );
        }

        return null;
    }
}
