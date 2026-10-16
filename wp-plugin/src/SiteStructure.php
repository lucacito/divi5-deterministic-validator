<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A READ-ONLY view of how this site is set up: public post types, taxonomies, image sizes and, when
 * Advanced Custom Fields is active, its field groups and fields (names, labels, types, choices; never
 * field values). Also the custom field KEYS the site's recent posts use (a sample; keys only, never values), and Divi 5.15's
 * Components (name, kind, Slot ids and how many pages use each; never their content). Everything comes from WordPress's and ACF's own public read functions, each behind a
 * guard: a missing or throwing reader means an empty section plus a note. Nothing here writes anything,
 * and nothing reads theme files or menus.
 */
final class SiteStructure
{
    public const SECTIONS    = [ 'post_types', 'taxonomies', 'image_sizes', 'acf', 'custom_fields', 'components' ];
    public const MAX_ITEMS   = 100;
    public const MAX_FIELDS  = 100;
    public const MAX_CHOICES = 50;
    public const MAX_TEXT    = 200;
    /** A group's location is a list of alternative rule sets; each set is a list of rules that must all match. */
    public const MAX_LOCATION_SETS  = 10;
    public const MAX_LOCATION_RULES = 10;

    /**
     * The sections a caller asked for: every section for null, '' or [], a comma-separated string or a
     * list of section names otherwise. Anything else (an unknown name, a non-string entry, a number, a
     * boolean, an associative array) is invalid: null, so the caller answers 400 / -32602.
     *
     * @return ?list<string>
     */
    public static function parseInclude( mixed $value ): ?array
    {
        if ( null === $value || '' === $value || [] === $value ) {
            return self::SECTIONS;
        }
        if ( is_string( $value ) ) {
            $value = array_map( 'trim', explode( ',', $value ) );
        }
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            return null;
        }
        $wanted = [];
        foreach ( $value as $section ) {
            if ( ! is_string( $section ) || ! in_array( $section, self::SECTIONS, true ) ) {
                return null;
            }
            $wanted[ $section ] = true;
        }

        return array_values( array_filter( self::SECTIONS, static fn ( string $s ): bool => isset( $wanted[ $s ] ) ) );
    }

    /** @return array<string, ?callable> */
    public static function readers(): array
    {
        return [
            'post_types'  => function_exists( 'get_post_types' ) ? [ self::class, 'readPostTypes' ] : null,
            'taxonomies'  => function_exists( 'get_taxonomies' ) ? [ self::class, 'readTaxonomies' ] : null,
            'image_sizes' => function_exists( 'wp_get_registered_image_subsizes' ) ? 'wp_get_registered_image_subsizes' : null,
            'acf_groups'  => function_exists( 'acf_get_field_groups' ) ? 'acf_get_field_groups' : null,
            'acf_fields'  => function_exists( 'acf_get_fields' ) ? 'acf_get_fields' : null,
            'custom_fields' => class_exists( CustomFieldReader::class ) ? [ CustomFieldReader::class, 'discover' ] : null,
            'components'  => class_exists( ComponentReader::class ) ? [ ComponentReader::class, 'discover' ] : null,
        ];
    }

    /** @return array<string,array<string,mixed>> name => raw post type data */
    public static function readPostTypes(): array
    {
        $out = [];
        foreach ( (array) get_post_types( [ 'public' => true ], 'objects' ) as $name => $type ) {
            $name         = (string) $name;
            $counts       = function_exists( 'wp_count_posts' ) ? (array) wp_count_posts( $name ) : [];
            $statusKey    = 'attachment' === $name ? 'inherit' : 'publish';
            $out[ $name ] = [
                'label'        => (string) ( $type->label ?? $name ),
                'supports'     => function_exists( 'get_all_post_type_supports' ) ? array_keys( array_filter( (array) get_all_post_type_supports( $name ) ) ) : [],
                'taxonomies'   => function_exists( 'get_object_taxonomies' ) ? array_values( (array) get_object_taxonomies( $name ) ) : [],
                'has_archive'  => (bool) ( $type->has_archive ?? false ),
                'hierarchical' => (bool) ( $type->hierarchical ?? false ),
                'count'        => (int) ( $counts[ $statusKey ] ?? 0 ),
            ];
        }

        return $out;
    }

    /** @return array<string,array<string,mixed>> name => raw taxonomy data */
    public static function readTaxonomies(): array
    {
        $out = [];
        foreach ( (array) get_taxonomies( [ 'public' => true ], 'objects' ) as $name => $tax ) {
            $name           = (string) $name;
            $out[ $name ] = [
                'label'        => (string) ( $tax->label ?? $name ),
                'object_types' => array_values( (array) ( $tax->object_type ?? [] ) ),
                'hierarchical' => (bool) ( $tax->hierarchical ?? false ),
            ];
        }

        return $out;
    }

    /** What `get_site_structure` returns, read from the real WordPress and ACF functions. @return array<string,mixed> */
    public static function catalog( ?array $include = null ): array
    {
        return self::catalogWith( self::readers(), $include );
    }

    /**
     * @param array<string, ?callable> $readers post_types, taxonomies, image_sizes, acf_groups, acf_fields, custom_fields, components
     * @param ?list<string>            $include sections to return; null = all
     * @return array<string,mixed>
     */
    public static function catalogWith( array $readers, ?array $include = null ): array
    {
        $include   = $include ?? self::SECTIONS;
        $notes     = [];
        $truncated = false;
        $out       = [];

        if ( in_array( 'post_types', $include, true ) ) {
            $raw = self::read( $readers['post_types'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Post type data is not available on this site.';
            }
            $out['post_types'] = self::named( $raw ?? [], $truncated, static fn ( string $name, array $d ): array => [
                'name'         => $name,
                'label'        => self::text( $d['label'] ?? $name ),
                'supports'     => self::strings( $d['supports'] ?? [] ),
                'taxonomies'   => self::strings( $d['taxonomies'] ?? [] ),
                'has_archive'  => (bool) ( $d['has_archive'] ?? false ),
                'hierarchical' => (bool) ( $d['hierarchical'] ?? false ),
                'count'        => max( 0, (int) ( $d['count'] ?? 0 ) ),
            ] );
        }

        if ( in_array( 'taxonomies', $include, true ) ) {
            $raw = self::read( $readers['taxonomies'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Taxonomy data is not available on this site.';
            }
            $out['taxonomies'] = self::named( $raw ?? [], $truncated, static fn ( string $name, array $d ): array => [
                'name'         => $name,
                'label'        => self::text( $d['label'] ?? $name ),
                'object_types' => self::strings( $d['object_types'] ?? [] ),
                'hierarchical' => (bool) ( $d['hierarchical'] ?? false ),
            ] );
        }

        if ( in_array( 'image_sizes', $include, true ) ) {
            $raw = self::read( $readers['image_sizes'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Image size data is not available on this site.';
            }
            $out['image_sizes'] = self::named( $raw ?? [], $truncated, static fn ( string $name, array $d ): array => [
                'name'   => self::text( $name ),
                'width'  => (int) ( $d['width'] ?? 0 ),
                'height' => (int) ( $d['height'] ?? 0 ),
                'crop'   => ! empty( $d['crop'] ),
            ] );
        }

        if ( in_array( 'acf', $include, true ) ) {
            $out['acf'] = self::acf( $readers, $truncated, $notes );
        }

        if ( in_array( 'custom_fields', $include, true ) ) {
            $raw = self::read( $readers['custom_fields'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Custom field data is not available on this site.';
            }
            $out['custom_fields'] = [
                'post_types' => self::customFieldTypes( $raw['post_types'] ?? [] ),
                'note'       => 'A sample: the keys seen on the most recently modified posts of each type that you can edit, not every key the site has. Values are never listed here; call get_custom_fields with a post id to read one post\'s values.',
            ];
            $truncated = $truncated || ! empty( $raw['truncated'] );
        }

        if ( in_array( 'components', $include, true ) ) {
            $raw = self::read( $readers['components'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Divi Components are not available on this site (they need Divi 5.15 or newer).';
            }
            $out['components'] = [
                'active' => null !== $raw,
                'items'  => self::componentRows( $raw['components'] ?? [] ),
                'note'   => 'A component is shared: a change to a block that belongs to it shows on every page that uses it, and an instance on a page shows the component\'s own content. To give one page its own content, fill one of the component\'s Slots (slots lists their ids); adding a module next to a Slot changes the component itself.',
            ];
            $truncated = $truncated || ! empty( $raw['truncated'] );
        }

        return $out + [ 'truncated' => $truncated, 'notes' => $notes ];
    }

    /**
     * Each entry rebuilt from known fields, so nothing else a reader returns (such as a component's content) can pass through.
     *
     * @return list<array{id:int,name:string,kind:string,slots:list<string>,used_on:int}>
     */
    private static function componentRows( mixed $raw ): array
    {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $list = [];
        foreach ( $raw as $c ) {
            if ( ! is_array( $c ) || ! is_int( $c['id'] ?? null ) ) {
                continue;
            }
            if ( count( $list ) >= ComponentReader::MAX_COMPONENTS ) {
                break;
            }
            $list[] = [
                'id'      => $c['id'],
                'name'    => self::text( $c['name'] ?? '' ),
                'kind'    => in_array( $c['kind'] ?? '', [ 'section', 'row', 'module' ], true ) ? (string) $c['kind'] : '',
                'slots'   => array_slice( self::strings( $c['slots'] ?? [] ), 0, ComponentReader::MAX_SLOTS ),
                'used_on' => max( 0, (int) ( $c['used_on'] ?? 0 ) ),
            ];
        }

        return $list;
    }

    /**
     * @param array<string, ?callable> $readers
     * @param list<string>             $notes
     * @return array{active: bool, groups: list<array<string,mixed>>}
     */
    private static function acf( array $readers, bool &$truncated, array &$notes ): array
    {
        $groups = self::read( $readers['acf_groups'] ?? null );
        if ( null === $groups ) {
            $notes[] = 'Advanced Custom Fields is not active on this site.';

            return [ 'active' => false, 'groups' => [] ];
        }

        $list = [];
        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) || ( array_key_exists( 'active', $group ) && ! $group['active'] ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_ITEMS ) {
                $truncated = true;
                break;
            }
            $key     = is_string( $group['key'] ?? null ) ? $group['key'] : '';
            $fields  = '' === $key ? null : self::read( $readers['acf_fields'] ?? null, [ $key ] );
            $list[]  = [
                'key'      => $key,
                'title'    => self::text( $group['title'] ?? '' ),
                'location' => self::location( $group['location'] ?? null, $truncated ),
                'fields'   => self::fields( $fields ?? [], $truncated ),
            ];
        }

        return [ 'active' => true, 'groups' => $list ];
    }

    /**
     * Where an ACF field group applies, as ACF stores it: a list of alternative rule sets (any one matching is enough),
     * each a list of rules that must all match, shown as "param operator value" (for example "post_type == page").
     * Unusable data gives an empty list.
     *
     * @return list<list<string>>
     */
    private static function location( mixed $raw, bool &$truncated ): array
    {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $sets = [];
        foreach ( $raw as $set ) {
            if ( ! is_array( $set ) ) {
                continue;
            }
            $rules = [];
            foreach ( $set as $rule ) {
                if ( ! is_array( $rule ) || ! is_string( $rule['param'] ?? null ) || ! is_string( $rule['operator'] ?? null ) || ! is_scalar( $rule['value'] ?? null ) ) {
                    continue;
                }
                if ( count( $rules ) >= self::MAX_LOCATION_RULES ) {
                    $truncated = true;
                    break;
                }
                $rules[] = self::text( $rule['param'] . ' ' . $rule['operator'] . ' ' . $rule['value'] );
            }
            if ( [] === $rules ) {
                continue;
            }
            if ( count( $sets ) >= self::MAX_LOCATION_SETS ) {
                $truncated = true;
                break;
            }
            $sets[] = $rules;
        }

        return $sets;
    }

    /**
     * Keys only: each entry is rebuilt from known fields, so nothing else a reader returns (such as a value) can pass through.
     *
     * @return list<array<string,mixed>>
     */
    private static function customFieldTypes( mixed $raw ): array
    {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $list = [];
        foreach ( $raw as $type ) {
            if ( ! is_array( $type ) || ! is_string( $type['post_type'] ?? null ) || ! is_array( $type['keys'] ?? null ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_ITEMS ) {
                break;
            }
            $keys = [];
            foreach ( $type['keys'] as $k ) {
                if ( ! is_array( $k ) || ! is_string( $k['key'] ?? null ) ) {
                    continue;
                }
                if ( count( $keys ) >= CustomFieldReader::MAX_KEYS_PER_TYPE ) {
                    break;
                }
                $acf    = $k['acf'] ?? null;
                $keys[] = [
                    'key'   => self::text( $k['key'] ),
                    'posts' => (int) ( $k['posts'] ?? 0 ),
                    'acf'   => is_array( $acf ) ? [ 'label' => self::text( $acf['label'] ?? '' ), 'type' => self::text( $acf['type'] ?? '' ) ] : null,
                ];
            }
            $list[] = [ 'post_type' => self::text( $type['post_type'] ), 'sampled' => (int) ( $type['sampled'] ?? 0 ), 'keys' => $keys ];
        }

        return $list;
    }

    /** @param array<int|string,mixed> $raw @return list<array<string,mixed>> */
    private static function fields( array $raw, bool &$truncated ): array
    {
        $list = [];
        foreach ( $raw as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_FIELDS ) {
                $truncated = true;
                break;
            }
            $field = [ 'name' => self::text( $f['name'] ?? '' ), 'label' => self::text( $f['label'] ?? '' ), 'type' => self::text( $f['type'] ?? '' ) ];
            if ( isset( $f['choices'] ) && is_array( $f['choices'] ) && [] !== $f['choices'] ) {
                $choices = [];
                foreach ( $f['choices'] as $value => $label ) {
                    if ( count( $choices ) >= self::MAX_CHOICES ) {
                        $truncated = true;
                        break;
                    }
                    $choices[] = [ 'value' => self::text( (string) $value ), 'label' => self::text( $label ) ];
                }
                $field['choices'] = $choices;
            }
            $list[] = $field;
        }

        return $list;
    }

    /** Calls a reader; null when it is missing, throws, or does not return an array. @param list<mixed> $args */
    private static function read( ?callable $reader, array $args = [] ): ?array
    {
        if ( null === $reader ) {
            return null;
        }
        try {
            $value = $reader( ...$args );
        } catch ( \Throwable $e ) {
            return null;
        }

        return is_array( $value ) ? $value : null;
    }

    /**
     * @param array<int|string,mixed> $raw
     * @param callable(string,array<string,mixed>):array<string,mixed> $map
     * @return list<array<string,mixed>>
     */
    private static function named( array $raw, bool &$truncated, callable $map ): array
    {
        $list = [];
        foreach ( $raw as $name => $data ) {
            if ( ! is_array( $data ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_ITEMS ) {
                $truncated = true;
                break;
            }
            $list[] = $map( (string) $name, $data );
        }

        return $list;
    }

    /** @return list<string> only the string entries, each cut */
    private static function strings( mixed $value ): array
    {
        return is_array( $value )
            ? array_values( array_map( [ self::class, 'text' ], array_filter( $value, 'is_string' ) ) )
            : [];
    }

    private static function text( mixed $value ): string
    {
        $text = is_scalar( $value ) ? (string) $value : '';

        return mb_substr( $text, 0, self::MAX_TEXT );
    }
}
