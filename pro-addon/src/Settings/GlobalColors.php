<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY code that talks to Divi's global colors, and only through Divi's own class (never its option names).
 * Divi's save REPLACES the whole custom map, so a write always carries the complete map; every read goes through
 * `GlobalData::get_global_colors()`, except that the five theme (Customizer) colors report their LIVE value, because
 * Divi caches those in a static for the whole request. Anything missing, throwing or unreadable fails closed.
 */
final class GlobalColors
{
    public const DATA_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalData';

    /** The five colors Divi generates from the theme's own settings. */
    public const THEME_IDS = [ 'gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color', 'gcid-link-color' ];

    public const MAX_LABEL = 60;

    public static function available( ?string $class = null ): bool
    {
        $class ??= self::DATA_CLASS;

        return is_callable( [ $class, 'get_global_colors' ] ) && is_callable( [ $class, 'set_global_colors' ] );
    }

    public static function isThemeId( string $id ): bool
    {
        return in_array( $id, self::THEME_IDS, true );
    }

    /**
     * @return array<string,array<string,mixed>>|null id => the entry as Divi returned it (a theme color carries its live value); null when unreadable
     */
    public static function read(): ?array
    {
        if ( ! self::available() ) {
            return null;
        }
        try {
            $all = call_user_func( [ self::DATA_CLASS, 'get_global_colors' ] );
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( ! is_array( $all ) ) {
            return null;
        }
        $out = [];
        foreach ( $all as $id => $entry ) {
            if ( ! is_string( $id ) || ! is_array( $entry ) ) {
                return null; // Divi's replace-all save would silently lose an entry we cannot read faithfully
            }
            if ( self::isThemeId( $id ) ) {
                $live = self::liveThemeColor( $id );
                if ( null !== $live ) {
                    $entry['color'] = $live;
                }
            }
            $out[ $id ] = $entry;
        }

        return $out;
    }

    /** What counts as "this color changed": never lastUpdated (a theme color gets a fresh one on every read) or usage. */
    public static function state( ?array $entry ): ?array
    {
        if ( null === $entry ) {
            return null;
        }

        return [
            'color'  => (string) ( $entry['color'] ?? '' ),
            'label'  => (string) ( $entry['label'] ?? '' ),
            'status' => (string) ( $entry['status'] ?? 'active' ),
            'folder' => (string) ( $entry['folder'] ?? '' ),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $all
     * @return array<string,array<string,mixed>> the custom entries only: Divi stores a theme color in the theme's own settings
     */
    public static function customMap( array $all ): array
    {
        $out = [];
        foreach ( $all as $id => $entry ) {
            if ( ! self::isThemeId( (string) $id ) ) {
                $out[ $id ] = $entry;
            }
        }

        return $out;
    }

    /**
     * The entry Divi takes to change a theme color (Divi uses only its `color`).
     *
     * @return array<string,mixed>
     */
    public static function themeEntry( string $color, string $label, string $stamp ): array
    {
        return [ 'color' => $color, 'folder' => 'customizer', 'label' => $label, 'lastUpdated' => $stamp, 'status' => 'active', 'usedInPosts' => [] ];
    }

    /**
     * Hands Divi the COMPLETE map once (Divi replaces the whole custom map; sanitising stays on) and reads back what is stored now.
     *
     * @param array<string,array<string,mixed>> $map
     * @return array{threw:bool, after:?array<string,array<string,mixed>>}
     */
    public static function write( array $map ): array
    {
        $threw = false;
        if ( self::available() ) {
            try {
                call_user_func( [ self::DATA_CLASS, 'set_global_colors' ], $map );
            } catch ( \Throwable $e ) {
                $threw = true;
            }
        } else {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    public static function stamp( int $ts ): string
    {
        return gmdate( 'Y-m-d\TH:i:s', $ts ) . '.000Z';
    }

    /** A color value that ends up in the site's CSS: a hex value or rgb/rgba/hsl/hsla of plain numbers, nothing else. */
    public static function validColor( string $color ): bool
    {
        if ( strlen( $color ) > 64 ) {
            return false;
        }

        // A literal space only (never \s: it would let \n, \v and \f into the CSS) and a real number (never a bare dot).
        $n = '(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)%?';

        return 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/Di', $color )
            || 1 === preg_match( '/^(?:rgb|rgba|hsl|hsla)\( *' . $n . '(?: *[,\/ ] *' . $n . '){2,3} *\)$/Di', $color );
    }

    /**
     * What Divi's own save would delete or flatten if it were handed this map (the exact map that is about to be sent): an entry with
     * an empty color or an id that is 'undefined' or does not start with gcid- is dropped (a color whose VALUE is the word undefined is kept), any array or null value other than a list of
     * scalars in usedInPosts is flattened to '', and a usedInPosts that is not an array makes Divi throw. Refusing beforehand turns
     * silent loss (which no batch could undo) into a clear message, and API drift into a refusal.
     *
     * @param array<mixed> $map
     * @return list<array{id:string,reason:string}>
     */
    public static function unsafeEntries( array $map ): array
    {
        $out = [];
        foreach ( $map as $id => $entry ) {
            $reason = self::unsafeReason( $id, $entry );
            if ( null !== $reason ) {
                $out[] = [ 'id' => (string) $id, 'reason' => $reason ];
            }
        }

        return $out;
    }

    private static function unsafeReason( mixed $id, mixed $entry ): ?string
    {
        if ( ! is_string( $id ) || ! str_starts_with( $id, 'gcid-' ) ) {
            return 'bad_id';
        }
        if ( ! is_array( $entry ) ) {
            return 'not_an_entry';
        }
        $color = $entry['color'] ?? '';
        if ( ! is_scalar( $color ) || empty( $color ) ) {
            return 'empty_color';
        }
        foreach ( $entry as $key => $value ) {
            if ( 'usedInPosts' === $key ) {
                if ( ! is_array( $value ) ) {
                    return 'bad_used_in_posts';
                }
                foreach ( $value as $post ) {
                    if ( ! is_scalar( $post ) ) {
                        return 'bad_used_in_posts';
                    }
                }
            } elseif ( ! is_scalar( $value ) ) {
                return 'non_scalar_field';
            }
        }

        return null;
    }

    /** @return string|null the label as it will be stored, or null when it is not acceptable */
    public static function cleanLabel( string $label ): ?string
    {
        if ( 1 !== preg_match( '//u', $label ) || 1 === preg_match( '/[\x00-\x1F\x7F<>]/', $label ) ) {
            return null;
        }
        $clean = sanitize_text_field( $label );
        if ( '' === $clean || mb_strlen( $clean ) > self::MAX_LABEL ) {
            return null;
        }

        return $clean;
    }

    public static function validId( string $id ): bool
    {
        return 1 === preg_match( '/^gcid-[A-Za-z0-9-]{3,64}$/D', $id );
    }

    private static function liveThemeColor( string $id ): ?string
    {
        $class = self::DATA_CLASS;
        if ( ! property_exists( $class, 'customizer_colors' ) || ! function_exists( 'et_get_option' ) ) {
            return null;
        }
        $defs = $class::$customizer_colors;
        $def  = is_array( $defs ) ? ( $defs[ $id ] ?? null ) : null;
        if ( ! is_array( $def ) || ! isset( $def['option_name'], $def['default'] ) ) {
            return null;
        }

        return sanitize_text_field( (string) et_get_option( $def['option_name'], $def['default'] ) );
    }
}
