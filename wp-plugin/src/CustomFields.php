<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which custom-field keys a layout may bind Divi dynamic content to. A key is "known" when it is an Advanced Custom Fields
 * field on this site (read through ACF's own public functions, behind function_exists guards) or when at least one post
 * already has that meta key. A key that is neither renders an empty module in Divi, with no error, so the style-reference
 * advisory reports it. Read-only: nothing is written, nothing is requested from another host.
 */
final class CustomFields
{
    /** At most this many keys are looked up in the database per request; past it a key is reported as known (never "missing"). */
    public const MAX_LOOKUPS = 50;

    /** ACF field types stored under their own name plus a numbered or flattened suffix, so their prefix counts as known. */
    private const PREFIXED_TYPES = [ 'repeater', 'flexible_content' ];

    /**
     * Lazy: ACF's field groups are read on the first lookup, so a layout that binds no custom field costs nothing.
     *
     * @return \Closure(string):bool true when the meta key is an ACF field or any post has it
     */
    public static function lookup(): \Closure
    {
        $inner = null;

        return static function ( string $key ) use ( &$inner ): bool {
            $inner ??= self::lookupWith(
                self::acfNamesWith(
                    function_exists( 'acf_get_field_groups' ) ? 'acf_get_field_groups' : null,
                    function_exists( 'acf_get_fields' ) ? 'acf_get_fields' : null
                ),
                [ self::class, 'postHasMeta' ]
            );

            return $inner( $key );
        };
    }

    /**
     * @param array{names: array<string,true>, prefixes: list<string>} $acf
     * @param callable(string):bool $metaExists
     * @return \Closure(string):bool
     */
    public static function lookupWith( array $acf, callable $metaExists ): \Closure
    {
        $memo  = [];
        $asked = 0;

        return static function ( string $key ) use ( $acf, $metaExists, &$memo, &$asked ): bool {
            if ( isset( $acf['names'][ $key ] ) ) {
                return true;
            }
            foreach ( $acf['prefixes'] as $prefix ) {
                if ( str_starts_with( $key, $prefix ) ) {
                    return true;
                }
            }
            if ( isset( $memo[ $key ] ) ) {
                return $memo[ $key ];
            }
            if ( $asked >= self::MAX_LOOKUPS ) {
                return true;
            }
            ++$asked;

            return $memo[ $key ] = (bool) $metaExists( $key );
        };
    }

    /**
     * ACF field names as they are stored in post meta: top-level names, group fields as group_sub, and a prefix for
     * repeaters and flexible content (stored as name_0_sub). Empty when ACF cannot be read.
     *
     * @return array{names: array<string,true>, prefixes: list<string>}
     */
    public static function acfNamesWith( ?callable $groups, ?callable $fields ): array
    {
        $out = [ 'names' => [], 'prefixes' => [] ];
        if ( null === $groups || null === $fields ) {
            return $out;
        }
        try {
            $list = $groups();
        } catch ( \Throwable ) {
            return $out;
        }
        if ( ! is_array( $list ) ) {
            return $out;
        }
        foreach ( $list as $group ) {
            $key = is_array( $group ) && is_string( $group['key'] ?? null ) ? $group['key'] : '';
            if ( '' === $key ) {
                continue;
            }
            try {
                $found = $fields( $key );
            } catch ( \Throwable ) {
                continue;
            }
            if ( is_array( $found ) ) {
                self::collect( $found, '', $out );
            }
        }
        $out['prefixes'] = array_values( array_unique( $out['prefixes'] ) );

        return $out;
    }

    /** One indexed lookup: does any post (any type, any status) have this meta key? */
    public static function postHasMeta( string $key ): bool
    {
        $ids = get_posts( [
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'none',
            'no_found_rows'  => true,
            'meta_key'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one indexed lookup per field cited in a layout, capped by MAX_LOOKUPS
        ] );

        return is_array( $ids ) && [] !== $ids;
    }

    /**
     * @param array<mixed> $fields
     * @param array{names: array<string,true>, prefixes: list<string>} $out
     */
    private static function collect( array $fields, string $prefix, array &$out ): void
    {
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name = is_string( $field['name'] ?? null ) ? $field['name'] : '';
            if ( '' === $name ) {
                continue;
            }
            $stored = $prefix . $name;
            $type   = is_string( $field['type'] ?? null ) ? $field['type'] : '';
            $out['names'][ $stored ] = true;
            if ( in_array( $type, self::PREFIXED_TYPES, true ) ) {
                $out['prefixes'][] = $stored . '_';
            } elseif ( 'group' === $type && is_array( $field['sub_fields'] ?? null ) ) {
                self::collect( $field['sub_fields'], $stored . '_', $out );
            }
        }
    }
}
