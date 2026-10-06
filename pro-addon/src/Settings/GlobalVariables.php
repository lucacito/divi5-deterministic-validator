<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY code that talks to Divi's global variables, and only through Divi's own class (never its option name).
 * Divi's save REPLACES the whole map and keeps only six groups, so a write always carries the complete map; every
 * read goes through `GlobalData::get_global_variables()`, minus what Divi itself adds on read (`allowedActions` and the
 * built-in customizer fonts, which must never be stored). Anything missing, throwing or unreadable fails closed.
 * Entries are arrays of strings: Divi's sanitiser turns every value (and `order`) into a string.
 */
final class GlobalVariables
{
    public const DATA_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalData';

    /** The six groups Divi's save keeps; anything else (a stored `colors` group) is erased by it. */
    public const TYPES = [ 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ];

    /** The two groups this add-on writes. */
    public const MANAGED = [ 'numbers', 'strings' ];

    public const STATUSES = [ 'active', 'archived' ];

    public static function available( ?string $class = null ): bool
    {
        $class ??= self::DATA_CLASS;

        return is_callable( [ $class, 'get_global_variables' ] ) && is_callable( [ $class, 'set_global_variables' ] );
    }

    /** Divi's own bar: set_global_variables() silently writes nothing without both. */
    public static function permitted(): bool
    {
        return current_user_can( 'edit_theme_options' ) && function_exists( 'et_pb_is_allowed' ) && (bool) et_pb_is_allowed( 'variables_manager' );
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>|null type => id => entry as stored; null when unreadable
     */
    public static function read(): ?array
    {
        if ( ! self::available() ) {
            return null;
        }
        try {
            $all = call_user_func( [ self::DATA_CLASS, 'get_global_variables' ] );
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( ! is_array( $all ) ) {
            return null;
        }
        $skip = self::customizerFontIds();
        $out  = [];
        foreach ( $all as $type => $group ) {
            if ( ! is_string( $type ) ) {
                return null;
            }
            if ( is_object( $group ) ) {
                $group = (array) $group;
            }
            if ( ! is_array( $group ) ) {
                return null;
            }
            $entries = [];
            foreach ( $group as $id => $entry ) {
                $id = (string) $id;
                if ( 'fonts' === $type && in_array( $id, $skip, true ) ) {
                    continue;
                }
                if ( ! is_array( $entry ) ) {
                    return null; // Divi's replace-all save could not give this entry back faithfully
                }
                unset( $entry['allowedActions'] );
                $entries[ $id ] = $entry;
            }
            $out[ $type ] = $entries;
        }

        return $out;
    }

    /** What counts as "this variable changed": never `order`. */
    public static function state( ?array $entry ): ?array
    {
        if ( null === $entry ) {
            return null;
        }

        return [
            'label'  => self::str( $entry['label'] ?? '' ),
            'value'  => self::str( $entry['value'] ?? '' ),
            'status' => self::str( $entry['status'] ?? 'active' ),
        ];
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $all
     * @return array{type:string,entry:array<string,mixed>}|null
     */
    public static function find( array $all, string $id ): ?array
    {
        foreach ( $all as $type => $group ) {
            if ( is_array( $group ) && isset( $group[ $id ] ) && is_array( $group[ $id ] ) ) {
                return [ 'type' => (string) $type, 'entry' => $group[ $id ] ];
            }
        }

        return null;
    }

    /** @param array<string,array<string,mixed>> $group one group's entries */
    public static function nextOrder( array $group ): int
    {
        $max = 0;
        foreach ( $group as $entry ) {
            if ( is_array( $entry ) && isset( $entry['order'] ) && is_scalar( $entry['order'] ) ) {
                $max = max( $max, (int) $entry['order'] );
            }
        }

        return $max + 1;
    }

    /**
     * The groups that hold entries: what is handed to Divi (an empty group adds nothing, and a non-empty `colors` group stays in
     * so that unsafeEntries() can refuse it).
     *
     * @param array<string,mixed> $all
     * @return array<string,mixed>
     */
    public static function sendMap( array $all ): array
    {
        $out = [];
        foreach ( $all as $type => $group ) {
            if ( is_array( $group ) && [] !== $group ) {
                $out[ $type ] = $group;
            }
        }

        return $out;
    }

    /**
     * Hands Divi the COMPLETE map once (it replaces everything; sanitising stays on) and reads back what is stored now.
     *
     * @param array<string,mixed> $map
     * @return array{threw:bool, after:?array<string,array<string,array<string,mixed>>>}
     */
    public static function write( array $map ): array
    {
        $threw = false;
        if ( self::available() ) {
            try {
                call_user_func( [ self::DATA_CLASS, 'set_global_variables' ], $map );
            } catch ( \Throwable $e ) {
                $threw = true;
            }
        } else {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    /**
     * What Divi's own save would delete or flatten if it were handed this map: any group that is not one of the six (a stored
     * `colors` group), an entry with an empty label (PHP `empty()`, so also "0"), an id that is `undefined` or does not start with
     * `gvid-`, and any non-scalar value (the sanitiser flattens it to ''). Refusing beforehand turns silent loss, which no batch
     * could undo, into a clear message.
     *
     * @param array<mixed> $map type => id => entry (what sendMap() returns)
     * @return list<array{id:string,reason:string}>
     */
    public static function unsafeEntries( array $map ): array
    {
        $out = [];
        foreach ( $map as $type => $group ) {
            if ( ! in_array( $type, self::TYPES, true ) ) {
                if ( ! is_array( $group ) || [] !== $group ) {
                    $out[] = [ 'id' => (string) $type, 'reason' => 'unsupported_type' ];
                }
                continue;
            }
            if ( ! is_array( $group ) ) {
                $out[] = [ 'id' => (string) $type, 'reason' => 'bad_group' ];
                continue;
            }
            foreach ( $group as $id => $entry ) {
                $reason = self::unsafeReason( $id, $entry );
                if ( null !== $reason ) {
                    $out[] = [ 'id' => (string) $id, 'reason' => $reason ];
                }
            }
        }

        return $out;
    }

    public static function validId( string $id ): bool
    {
        return 1 === preg_match( '/^gvid-[A-Za-z0-9-]{3,64}$/D', $id );
    }

    private static function unsafeReason( mixed $id, mixed $entry ): ?string
    {
        if ( ! is_string( $id ) || 'undefined' === $id || ! str_starts_with( $id, 'gvid-' ) ) {
            return 'bad_id';
        }
        if ( ! is_array( $entry ) ) {
            return 'not_an_entry';
        }
        if ( empty( $entry['label'] ) ) {
            return 'empty_label';
        }
        foreach ( $entry as $key => $value ) {
            if ( 'allowedActions' !== $key && ! is_scalar( $value ) ) {
                return 'non_scalar_field';
            }
        }

        return null;
    }

    /** @return list<string> the ids of Divi's built-in font variables (empty when this Divi does not expose them) */
    private static function customizerFontIds(): array
    {
        $class = self::DATA_CLASS;
        if ( ! property_exists( $class, 'customizer_fonts' ) ) {
            return [];
        }
        $fonts = $class::$customizer_fonts;

        return is_array( $fonts ) ? array_map( 'strval', array_keys( $fonts ) ) : [];
    }

    private static function str( mixed $value ): string
    {
        return is_scalar( $value ) ? (string) $value : '';
    }
}
