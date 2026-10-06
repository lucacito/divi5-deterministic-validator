<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

use AiEditorDivi5\WP\CodePolicy;
use Divi5Validator\SchemaRules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY code that talks to Divi's module presets, and only through Divi's own class (never its option name). Divi's PHP
 * functions check no capability, so permitted() is enforced here. A create goes through Divi's ADDITIVE import; an update or a
 * removal needs the complete data and Divi's replace-all save. Anything missing, throwing or of an unexpected shape fails closed.
 */
final class GlobalPresets
{
    public const PRESET_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalPreset';

    public const MAX_ATTRS_BYTES = 20000;
    public const MAX_DEPTH       = 12;

    public static function available( ?string $class = null ): bool
    {
        $class ??= self::PRESET_CLASS;
        foreach ( [ 'get_data', 'save_data', 'process_presets_for_import' ] as $method ) {
            if ( ! is_callable( [ $class, $method ] ) ) {
                return false;
            }
        }

        return true;
    }

    /** Divi's own functions check nothing, so this is the bar. */
    public static function permitted(): bool
    {
        return current_user_can( 'edit_theme_options' );
    }

    /** @return array<string,mixed>|null Divi's data as returned; null when unreadable */
    public static function read(): ?array
    {
        if ( ! self::available() ) {
            return null;
        }
        try {
            $data = call_user_func( [ self::PRESET_CLASS, 'get_data' ] );
        } catch ( \Throwable $e ) {
            return null;
        }

        return is_array( $data ) ? $data : null;
    }

    /**
     * Shape problems that make a replace-all save unsafe: a save writes back exactly what it is given, so it must be given something
     * it can reproduce faithfully.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    public static function unsafe( array $data ): array
    {
        $out = [];
        foreach ( $data as $kind => $modules ) {
            if ( ! in_array( $kind, [ 'module', 'group' ], true ) ) {
                $out[] = 'an unknown top-level key "' . $kind . '"';
                continue;
            }
            if ( ! is_array( $modules ) ) {
                $out[] = '"' . $kind . '" is not a list';
                continue;
            }
            foreach ( $modules as $name => $entry ) {
                if ( ! is_array( $entry ) || ! is_array( $entry['items'] ?? null ) ) {
                    $out[] = '"' . $kind . '/' . $name . '" has no items list';
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{module:string,item:array<string,mixed>}|null
     */
    public static function find( array $data, string $id ): ?array
    {
        foreach ( (array) ( $data['module'] ?? [] ) as $module => $entry ) {
            $item = is_array( $entry ) && is_array( $entry['items'] ?? null ) ? ( $entry['items'][ $id ] ?? null ) : null;
            if ( is_array( $item ) ) {
                return [ 'module' => (string) $module, 'item' => $item ];
            }
        }

        return null;
    }

    /** A preset this tool may target: a normal `divi/...` module key whose item names that same module and has a name and an attrs tree. @param array<string,mixed> $item */
    public static function managed( string $module, array $item ): bool
    {
        return self::validModule( $module )
            && ( $item['moduleName'] ?? null ) === $module
            && is_string( $item['name'] ?? null )
            && is_array( $item['attrs'] ?? null );
    }

    /** What counts as "this preset changed": its name, its module and its style tree. @param array<string,mixed>|null $item */
    public static function state( ?array $item ): ?array
    {
        if ( null === $item ) {
            return null;
        }

        return [
            'name'       => is_scalar( $item['name'] ?? null ) ? (string) $item['name'] : '',
            'moduleName' => is_scalar( $item['moduleName'] ?? null ) ? (string) $item['moduleName'] : '',
            'attrs'      => is_array( $item['attrs'] ?? null ) ? $item['attrs'] : [],
        ];
    }

    /** @param array<string,mixed> $data */
    public static function defaultId( array $data, string $module ): string
    {
        $default = $data['module'][ $module ]['default'] ?? '';

        return is_string( $default ) ? $default : '';
    }

    /** @param array<string,mixed> $data @return list<string> */
    public static function itemIds( array $data, string $module ): array
    {
        $items = $data['module'][ $module ]['items'] ?? [];

        return is_array( $items ) ? array_map( 'strval', array_keys( $items ) ) : [];
    }

    public static function validId( string $id ): bool
    {
        return 1 === preg_match( '/^[A-Za-z0-9-]{8,64}$/D', $id );
    }

    public static function validModule( string $module ): bool
    {
        return 1 === preg_match( '#^divi/[a-z0-9-]+$#D', $module ) && ( new SchemaRules() )->isKnownType( $module );
    }

    /**
     * Why an attribute tree may not be stored, as short problem texts (an empty list = acceptable). Custom CSS and code are refused by
     * the same policy the layout gate uses, run over the tree as the attributes of one block of this module; the validator's
     * required-attribute rules do not apply (a preset holds partial attributes).
     *
     * @param array<mixed> $attrs
     * @return list<string>
     */
    public static function attrsProblems( string $module, array $attrs ): array
    {
        if ( [] === $attrs ) {
            return [ 'attrs must not be empty' ];
        }
        if ( array_is_list( $attrs ) ) {
            return [ 'attrs must be an object, not a list' ];
        }
        $json = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
        if ( false === $json ) {
            return [ 'attrs are not valid JSON text (invalid UTF-8?)' ];
        }
        if ( strlen( $json ) > self::MAX_ATTRS_BYTES ) {
            return [ 'attrs are too large (at most ' . self::MAX_ATTRS_BYTES . ' bytes)' ];
        }
        if ( self::depth( $attrs ) > self::MAX_DEPTH ) {
            return [ 'attrs are nested too deep (at most ' . self::MAX_DEPTH . ' levels)' ];
        }
        $codes = [];
        foreach ( CodePolicy::violations( '<!-- wp:' . $module . ' ' . $json . ' /-->' ) as $violation ) {
            $codes[ $violation->code() ] = true;
        }

        return array_keys( $codes );
    }

    /**
     * The stored item for a new preset, the keys and order of a real one; Divi stamps created and updated again on import.
     *
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    public static function newItem( string $module, string $id, string $name, array $attrs, int $now ): array
    {
        return [
            'id'         => $id,
            'moduleName' => $module,
            'name'       => $name,
            'attrs'      => $attrs,
            'styleAttrs' => $attrs,
            'version'    => self::version(),
            'type'       => 'module',
            'created'    => $now,
            'updated'    => $now,
        ];
    }

    /**
     * Divi's additive import of ONE preset (it merges, never erases; for a module with no presets Divi also adds a default preset).
     *
     * @param array<string,mixed> $item
     * @return array{threw:bool, after:?array<string,mixed>}
     */
    public static function import( string $module, array $item, string $defaultId ): array
    {
        $threw = false;
        try {
            call_user_func(
                [ self::PRESET_CLASS, 'process_presets_for_import' ],
                [ 'module' => [ $module => [ 'default' => $defaultId, 'items' => [ (string) $item['id'] => $item ] ] ] ],
                true
            );
        } catch ( \Throwable $e ) {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    /**
     * Divi's replace-all save of the COMPLETE data, then a read-back.
     *
     * @param array<string,mixed> $data
     * @return array{threw:bool, after:?array<string,mixed>}
     */
    public static function save( array $data ): array
    {
        $threw = false;
        if ( self::available() ) {
            try {
                call_user_func( [ self::PRESET_CLASS, 'save_data' ], $data );
            } catch ( \Throwable $e ) {
                $threw = true;
            }
        } else {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    private static function version(): string
    {
        if ( defined( 'ET_BUILDER_VERSION' ) ) {
            return (string) ET_BUILDER_VERSION;
        }

        return '5.14.0';
    }

    /** @param array<mixed> $node */
    private static function depth( array $node ): int
    {
        $max = 1;
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                $max = max( $max, 1 + self::depth( $value ) );
            }
        }

        return $max;
    }
}
