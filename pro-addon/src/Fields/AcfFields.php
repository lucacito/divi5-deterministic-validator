<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ACF's field definitions as the writer needs them: stored key => field key, type, choices, whether a select is multi-value, and
 * whether the field is a member of a group. Read only through ACF's own public functions, each behind a function_exists guard;
 * a missing or throwing reader means an empty index (the writer then treats every key as a plain meta key). Nothing is written.
 */
final class AcfFields
{
    public const COMPLEX = 'is a complex ACF field type (a list, group, image, relationship and the like); edit it in the ACF editor';

    private const TEXT_TYPES = [ 'text', 'textarea', 'wysiwyg', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker' ];
    private const PREFIXED   = [ 'repeater', 'flexible_content', 'group' ];

    /** @return array{fields: array<string,array{field_key:string,type:string,choices:array<string,string>,multiple:bool,nested:bool}>, prefixes: list<string>} */
    public static function index(): array
    {
        return self::indexWith(
            function_exists( 'acf_get_field_groups' ) ? 'acf_get_field_groups' : null,
            function_exists( 'acf_get_fields' ) ? 'acf_get_fields' : null
        );
    }

    /** @return array{fields: array<string,array{field_key:string,type:string,choices:array<string,string>,multiple:bool,nested:bool}>, prefixes: list<string>} */
    public static function indexWith( ?callable $groups, ?callable $fields ): array
    {
        $out = [ 'fields' => [], 'prefixes' => [] ];
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
                self::collect( $found, '', false, $out );
            }
        }
        $out['prefixes'] = array_values( array_unique( $out['prefixes'] ) );

        return $out;
    }

    /**
     * Why a value cannot be written to this ACF field, or null when it can. The reason completes "the field ...".
     *
     * @param array{field_key:string,type:string,choices:array<string,string>,multiple:bool,nested:bool} $def
     */
    public static function refusalFor( array $def, string $value ): ?string
    {
        $type = $def['type'];
        if ( in_array( $type, self::TEXT_TYPES, true ) ) {
            return null;
        }
        if ( 'url' === $type ) {
            return '' === $value || 1 === preg_match( '#^(?:https?:)?//[^\s<>"\']+$#iD', $value ) ? null : 'must be a URL starting with http://, https:// or //';
        }
        if ( 'email' === $type ) {
            return '' === $value || 1 === preg_match( '/^[^@\s<>"\']+@[^@\s<>"\']+$/D', $value ) ? null : 'must be an email address';
        }
        if ( in_array( $type, [ 'number', 'range' ], true ) ) {
            return is_numeric( $value ) ? null : 'must be a number';
        }
        if ( 'true_false' === $type ) {
            return in_array( $value, [ '0', '1' ], true ) ? null : 'must be 0 or 1';
        }
        if ( in_array( $type, [ 'select', 'radio', 'button_group' ], true ) ) {
            if ( 'select' === $type && $def['multiple'] ) {
                return self::COMPLEX;
            }

            return array_key_exists( $value, $def['choices'] )
                ? null
                : 'must be one of: ' . implode( ', ', array_slice( array_map( 'strval', array_keys( $def['choices'] ) ), 0, 20 ) );
        }

        return self::COMPLEX;
    }

    /**
     * @param array<mixed>                                                                        $fields
     * @param array{fields: array<string,array<string,mixed>>, prefixes: list<string>} $out
     */
    private static function collect( array $fields, string $prefix, bool $nested, array &$out ): void
    {
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name = is_string( $field['name'] ?? null ) ? $field['name'] : '';
            if ( '' === $name ) {
                continue;
            }
            $stored  = $prefix . $name;
            $type    = is_string( $field['type'] ?? null ) ? $field['type'] : '';
            $choices = [];
            if ( is_array( $field['choices'] ?? null ) ) {
                foreach ( $field['choices'] as $value => $label ) {
                    $choices[ (string) $value ] = is_scalar( $label ) ? (string) $label : '';
                }
            }
            $out['fields'][ $stored ] = [
                'field_key' => is_string( $field['key'] ?? null ) ? $field['key'] : '',
                'type'      => $type,
                'choices'   => $choices,
                'multiple'  => ! empty( $field['multiple'] ),
                'nested'    => $nested,
            ];
            if ( in_array( $type, self::PREFIXED, true ) ) {
                $out['prefixes'][] = $stored . '_';
                if ( 'group' === $type && is_array( $field['sub_fields'] ?? null ) ) {
                    self::collect( $field['sub_fields'], $stored . '_', true, $out );
                }
            }
        }
    }
}
