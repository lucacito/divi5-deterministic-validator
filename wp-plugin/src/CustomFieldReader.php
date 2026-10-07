<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * READ-ONLY view of a post's custom fields (post meta). Every rule lives in the `*With()` methods, which take plain data
 * and callables so they run without WordPress; the real wrappers (`acfInfo()`, `read()` and `discover()`) only fetch
 * the data from WordPress and ACF's own public functions. Nothing here writes anything.
 *
 * Never returned: keys starting with an underscore or protected by WordPress. Withheld (and counted): keys that look like
 * secrets. The per-post `edit_post` check is the real protection; the secret filter is a heuristic on top of it.
 */
final class CustomFieldReader
{
    public const SHOWN  = 'shown';
    public const HIDDEN = 'hidden';
    public const SECRET = 'secret';

    public const MAX_FIELDS        = 100;
    public const MAX_VALUE         = 500;
    public const MAX_KEYS_ARG      = 100;
    public const SAMPLE_POSTS      = 20;
    public const MAX_KEYS_PER_TYPE = 100;

    private const SECRET_PATTERN = '/(password|passwd|secret|token|api[_-]?key|licen[cs]e)/i';
    private const MAX_LABEL      = 200;

    /** SHOWN, HIDDEN (never returned, not counted) or SECRET (never returned, counted as withheld). */
    public static function classify( string $key ): string
    {
        if ( '' === $key || str_starts_with( $key, '_' ) ) {
            return self::HIDDEN;
        }
        if ( function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'post' ) ) {
            return self::HIDDEN;
        }

        return 1 === preg_match( self::SECRET_PATTERN, $key ) ? self::SECRET : self::SHOWN;
    }

    /**
     * The `keys` argument: [] = no filter; a list of keys (a list or a comma separated string, trimmed, no duplicates);
     * null = invalid (a non-list, a non-string entry, an associative array or more than MAX_KEYS_ARG keys).
     *
     * @return ?list<string>
     */
    public static function parseKeys( mixed $value ): ?array
    {
        if ( null === $value || '' === $value || [] === $value ) {
            return [];
        }
        if ( is_string( $value ) ) {
            $value = explode( ',', $value );
        }
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            return null;
        }
        $keys = [];
        foreach ( $value as $key ) {
            if ( ! is_string( $key ) ) {
                return null;
            }
            $key = trim( $key );
            if ( '' !== $key ) {
                $keys[ $key ] = true;
            }
        }
        if ( count( $keys ) > self::MAX_KEYS_ARG ) {
            return null;
        }

        return array_map( 'strval', array_keys( $keys ) );
    }

    /**
     * @param list<string>                                  $keys    every meta key the post has
     * @param list<string>                                  $only    [] = all keys; otherwise only these
     * @param callable(string):mixed                        $valueOf loads one value; called ONLY for keys that are returned
     * @param array<string,array{label:string,type:string}> $acf     ACF info by stored key
     * @return array{fields: list<array{key:string,value:string,acf:?array{label:string,type:string}}>, withheld:int, truncated:bool}
     */
    public static function readWith( array $keys, array $only, callable $valueOf, array $acf ): array
    {
        $keys = array_map( 'strval', $keys );
        if ( [] !== $only ) {
            $keys = array_values( array_intersect( $keys, array_map( 'strval', $only ) ) );
        }
        sort( $keys, SORT_STRING );

        $fields    = [];
        $withheld  = 0;
        $truncated = false;
        foreach ( $keys as $key ) {
            $class = self::classify( $key );
            if ( self::HIDDEN === $class ) {
                continue;
            }
            if ( self::SECRET === $class ) {
                ++$withheld;
                continue;
            }
            if ( count( $fields ) >= self::MAX_FIELDS ) {
                $truncated = true;
                continue; // keep looping: later secret-looking keys are still counted
            }
            [ $text, $cut ] = self::valueText( $valueOf( $key ) );
            $truncated      = $truncated || $cut;
            $fields[]       = [ 'key' => $key, 'value' => $text, 'acf' => $acf[ $key ] ?? null ];
        }

        return [ 'fields' => $fields, 'withheld' => $withheld, 'truncated' => $truncated ];
    }

    /**
     * ACF label and type by the key ACF stores the value under (top-level names, group sub fields as group_sub). Empty
     * when ACF cannot be read.
     *
     * @return array<string,array{label:string,type:string}>
     */
    public static function acfInfoWith( ?callable $groups, ?callable $fields ): array
    {
        $out = [];
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
            $groupKey = is_array( $group ) && is_string( $group['key'] ?? null ) ? $group['key'] : '';
            if ( '' === $groupKey ) {
                continue;
            }
            try {
                $found = $fields( $groupKey );
            } catch ( \Throwable ) {
                continue;
            }
            if ( is_array( $found ) ) {
                self::collectAcf( $found, '', $out );
            }
        }

        return $out;
    }

    /** ACF label and type by stored key, read through ACF's own public functions behind function_exists guards. @return array<string,array{label:string,type:string}> */
    public static function acfInfo(): array
    {
        return self::acfInfoWith(
            function_exists( 'acf_get_field_groups' ) ? 'acf_get_field_groups' : null,
            function_exists( 'acf_get_fields' ) ? 'acf_get_fields' : null
        );
    }

    /**
     * What `get_custom_fields` answers for one post the caller already resolved through FieldTarget. A key stored several
     * times returns its first value. WordPress unserializes values itself; nothing here calls unserialize.
     *
     * @param list<string> $only [] = all keys
     * @return array{id:int,post_type:string,fields:list<array<string,mixed>>,withheld:int,truncated:bool}
     */
    public static function read( object $post, array $only ): array
    {
        $postId = (int) $post->ID;
        $custom = get_post_custom( $postId );
        $keys   = is_array( $custom ) ? array_map( 'strval', array_keys( $custom ) ) : [];

        return [ 'id' => $postId, 'post_type' => (string) $post->post_type ]
            + self::readWith( $keys, $only, static fn ( string $key ): mixed => get_post_meta( $postId, $key, true ), self::acfInfo() );
    }

    /**
     * What a site's posts use, read from WordPress: the newest SAMPLE_POSTS posts of each public post type except
     * attachments, only posts the current user can edit, keys only. No raw SQL: get_posts() plus the posts' own meta keys.
     *
     * @return array{post_types: list<array<string,mixed>>, truncated: bool}
     */
    public static function discover(): array
    {
        if ( ! function_exists( 'get_post_types' ) || ! function_exists( 'get_posts' ) ) {
            return [ 'post_types' => [], 'truncated' => false ];
        }
        $samples = [];
        foreach ( array_values( array_map( 'strval', array_keys( (array) get_post_types( [ 'public' => true ] ) ) ) ) as $type ) {
            if ( 'attachment' === $type ) {
                continue;
            }
            $ids = get_posts( [
                'post_type'      => $type,
                'post_status'    => 'any',
                'posts_per_page' => self::SAMPLE_POSTS,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );
            $ids = array_values( array_filter( array_map( 'intval', (array) $ids ), static fn ( int $id ): bool => $id > 0 && current_user_can( 'edit_post', $id ) ) );
            if ( [] === $ids ) {
                continue;
            }
            if ( function_exists( 'update_meta_cache' ) ) {
                update_meta_cache( 'post', $ids ); // one query for the whole sample
            }
            foreach ( $ids as $id ) {
                $custom             = get_post_custom( $id );
                $samples[ $type ][] = is_array( $custom ) ? array_map( 'strval', array_keys( $custom ) ) : [];
            }
        }

        return self::discoverWith( $samples, self::acfInfo() );
    }

    /**
     * What a site's posts use: for each post type with sampled posts, its shown keys with how many sampled posts have each,
     * most used first, then by name. Keys only, never values.
     *
     * @param array<string,list<list<string>>>              $samples post type => for each sampled post, its meta keys
     * @param array<string,array{label:string,type:string}> $acf
     * @return array{post_types: list<array{post_type:string,sampled:int,keys:list<array{key:string,posts:int,acf:?array{label:string,type:string}}>}>, truncated:bool}
     */
    public static function discoverWith( array $samples, array $acf ): array
    {
        $types     = [];
        $truncated = false;
        foreach ( $samples as $type => $posts ) {
            if ( ! is_array( $posts ) || [] === $posts ) {
                continue;
            }
            $counts = [];
            foreach ( $posts as $postKeys ) {
                foreach ( array_unique( array_map( 'strval', (array) $postKeys ) ) as $key ) {
                    if ( self::SHOWN !== self::classify( $key ) ) {
                        continue;
                    }
                    $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
                }
            }
            $keys = array_map( 'strval', array_keys( $counts ) );
            usort( $keys, static fn ( string $a, string $b ): int => [ $counts[ $b ], $a ] <=> [ $counts[ $a ], $b ] );
            if ( count( $keys ) > self::MAX_KEYS_PER_TYPE ) {
                $truncated = true;
                $keys      = array_slice( $keys, 0, self::MAX_KEYS_PER_TYPE );
            }
            $types[] = [
                'post_type' => (string) $type,
                'sampled'   => count( $posts ),
                'keys'      => array_map( static fn ( string $k ): array => [ 'key' => $k, 'posts' => (int) $counts[ $k ], 'acf' => $acf[ $k ] ?? null ], $keys ),
            ];
        }

        return [ 'post_types' => $types, 'truncated' => $truncated ];
    }

    /** @return array{0:string,1:bool} the value as text, cut at MAX_VALUE characters; whether it was cut */
    private static function valueText( mixed $value ): array
    {
        if ( is_array( $value ) || is_object( $value ) ) {
            $text = (string) wp_json_encode( $value );
        } elseif ( is_bool( $value ) ) {
            $text = $value ? '1' : '';
        } elseif ( is_scalar( $value ) ) {
            $text = (string) $value;
        } else {
            $text = '';
        }
        if ( mb_strlen( $text ) > self::MAX_VALUE ) {
            return [ mb_substr( $text, 0, self::MAX_VALUE ), true ];
        }

        return [ $text, false ];
    }

    /**
     * @param array<mixed>                                  $fields
     * @param array<string,array{label:string,type:string}> $out
     */
    private static function collectAcf( array $fields, string $prefix, array &$out ): void
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
            $label  = is_scalar( $field['label'] ?? null ) ? (string) $field['label'] : '';

            $out[ $stored ] = [ 'label' => mb_substr( $label, 0, self::MAX_LABEL ), 'type' => mb_substr( $type, 0, self::MAX_LABEL ) ];
            if ( 'group' === $type && is_array( $field['sub_fields'] ?? null ) ) {
                self::collectAcf( $field['sub_fields'], $stored . '_', $out );
            }
        }
    }
}
