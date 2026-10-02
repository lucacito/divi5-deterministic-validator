<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A READ-ONLY view of the site's Divi 5 module presets, global colors and global variables.
 * Everything is read through Divi's own public read functions (never through option names), each
 * behind a guard: when Divi's class or method is missing, or throws, that part is simply empty.
 * Nothing here writes, creates or changes anything.
 */
final class GlobalStyles
{
    public const MAX_PRESETS    = 300;
    public const MAX_COLORS     = 200;
    public const MAX_VARIABLES  = 100;
    public const MAX_CONTROLS   = 12;
    public const MAX_RENDERS    = 5;
    public const MAX_VALUE_CHARS = 200;

    public const PRESET_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalPreset';
    public const DATA_CLASS   = 'ET\\Builder\\Packages\\GlobalData\\GlobalData';

    /** The `folder` Divi gives the five colors it generates from the theme's own settings. */
    private const THEME_FOLDER = 'customizer';

    /** Depth of the dotted attribute paths shown in a preset's `controls`. */
    private const CONTROL_DEPTH = 3;

    /** @return array{0: ?callable, 1: ?callable, 2: ?callable} presets, colors, variables readers */
    public static function readers(): array
    {
        $pick = static fn ( string $class, string $method ): ?callable => is_callable( [ $class, $method ] ) ? [ $class, $method ] : null;

        return [
            $pick( self::PRESET_CLASS, 'get_data' ),
            $pick( self::DATA_CLASS, 'get_global_colors' ),
            $pick( self::DATA_CLASS, 'get_global_variables' ),
        ];
    }

    /** What `list_global_styles` returns, read from the real Divi functions. @return array<string,mixed> */
    public static function catalog( ?string $module = null ): array
    {
        [ $presets, $colors, $variables ] = self::readers();

        return self::catalogWith( $presets, $colors, $variables, $module );
    }

    /**
     * @param ?string $module only presets of this module name (e.g. divi/button); null or '' = all
     * @return array{presets: list<array<string,mixed>>, colors: list<array<string,string>>, variables: array<string,list<array<string,string>>>, counts: array<string,int>, truncated: bool, notes: list<string>}
     */
    public static function catalogWith( ?callable $presets, ?callable $colors, ?callable $variables, ?string $module = null ): array
    {
        $module = ( null === $module || '' === $module ) ? null : $module;
        $notes  = [];

        $presetData = self::read( $presets );
        $colorData  = self::read( $colors );
        $varData    = self::read( $variables );

        $presetList = null === $presetData ? [] : self::presetList( $presetData, $module );
        $colorList  = null === $colorData ? [] : self::colorList( $colorData );
        $varLists   = null === $varData ? [] : self::variableLists( $varData );

        $varTotal  = array_sum( array_map( 'count', $varLists ) );
        $truncated = count( $presetList ) > self::MAX_PRESETS || count( $colorList ) > self::MAX_COLORS;
        foreach ( $varLists as $list ) {
            $truncated = $truncated || count( $list ) > self::MAX_VARIABLES;
        }
        $counts = [ 'presets' => count( $presetList ), 'colors' => count( $colorList ), 'variables' => $varTotal ];

        if ( null === $presetData ) {
            $notes[] = "Divi's preset data is not available on this site.";
        } elseif ( [] === $presetList ) {
            $notes[] = null === $module ? 'This site has no module presets yet.' : "This site has no presets for the module '{$module}'.";
        }
        if ( null === $colorData ) {
            $notes[] = "Divi's global colors are not available on this site.";
        }
        if ( null === $varData ) {
            $notes[] = "Divi's global variables are not available on this site.";
        }
        if ( $truncated ) {
            $notes[] = 'Some lists were cut to their limit; pass "module" to narrow the presets.';
        }
        $notes[] = "Read-only. To create or change presets, colors or variables, use Divi's Visual Builder.";

        return [
            'presets'   => array_slice( $presetList, 0, self::MAX_PRESETS ),
            'colors'    => array_slice( $colorList, 0, self::MAX_COLORS ),
            'variables' => array_map( static fn ( array $l ): array => array_slice( $l, 0, self::MAX_VARIABLES ), $varLists ),
            'counts'    => $counts,
            'truncated' => $truncated,
            'notes'     => $notes,
        ];
    }

    /**
     * Every preset id and global color id that exists on this site, uncapped, for checking a layout's
     * references. A part is null when Divi cannot be read, so the caller never flags what it cannot know.
     *
     * @return array{presets: ?array<string,true>, colors: ?array<string,true>}
     */
    public static function knownIds(): array
    {
        [ $presets, $colors ] = self::readers();

        return self::knownIdsWith( $presets, $colors );
    }

    /** @return array{presets: ?array<string,true>, colors: ?array<string,true>} */
    public static function knownIdsWith( ?callable $presets, ?callable $colors ): array
    {
        $presetData = self::read( $presets );
        $colorData  = self::read( $colors );

        $presetIds = null;
        if ( null !== $presetData ) {
            $presetIds = [];
            foreach ( self::presetEntries( $presetData ) as [ , , $items ] ) {
                foreach ( $items as $key => $item ) {
                    if ( is_array( $item ) && is_string( $item['id'] ?? null ) && '' !== $item['id'] ) {
                        $presetIds[ $item['id'] ] = true;
                    }
                    if ( is_string( $key ) && '' !== $key ) {
                        $presetIds[ $key ] = true;
                    }
                }
            }
        }

        $colorIds = null;
        if ( null !== $colorData ) {
            $colorIds = [];
            foreach ( array_keys( $colorData ) as $id ) {
                if ( is_string( $id ) && '' !== $id ) {
                    $colorIds[ $id ] = true;
                }
            }
        }

        return [ 'presets' => $presetIds, 'colors' => $colorIds ];
    }

    /** Calls a Divi reader; null when it is missing, throws, or does not return an array. @return ?array<mixed> */
    private static function read( ?callable $reader ): ?array
    {
        if ( null === $reader ) {
            return null;
        }
        try {
            $data = $reader();
        } catch ( \Throwable ) {
            return null;
        }

        return is_array( $data ) ? $data : null;
    }

    /**
     * The usable module entries of the presets data as [module name, default id, items]. Anything
     * that is not a string module name with an array of items (for example the stray "0" entry seen
     * on a real site) is skipped, never an error.
     *
     * @param array<mixed> $data
     * @return list<array{0: string, 1: string, 2: array<mixed>}>
     */
    private static function presetEntries( array $data ): array
    {
        $out = [];
        $mods = $data['module'] ?? null;
        if ( ! is_array( $mods ) ) {
            return $out;
        }
        foreach ( $mods as $name => $entry ) {
            if ( ! is_string( $name ) || ! is_array( $entry ) || ! is_array( $entry['items'] ?? null ) ) {
                continue;
            }
            $out[] = [ $name, is_string( $entry['default'] ?? null ) ? $entry['default'] : '', $entry['items'] ];
        }

        return $out;
    }

    /** @param array<mixed> $data @return list<array<string,mixed>> */
    private static function presetList( array $data, ?string $module ): array
    {
        $out = [];
        foreach ( self::presetEntries( $data ) as [ $name, $default, $items ] ) {
            if ( null !== $module && $module !== $name ) {
                continue;
            }
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || '' === $item['id'] || ! is_string( $item['name'] ?? null ) ) {
                    continue;
                }
                $out[] = [
                    'id'       => $item['id'],
                    'module'   => $name,
                    'name'     => self::clip( $item['name'] ),
                    'default'  => $item['id'] === $default,
                    'controls' => self::controls( $item['attrs'] ?? null ),
                    'renders'  => self::renders( $item['renderAttrs'] ?? null ),
                ];
            }
        }
        usort( $out, static fn ( array $a, array $b ): int => [ $a['module'], $a['name'], $a['id'] ] <=> [ $b['module'], $b['name'], $b['id'] ] );

        return $out;
    }

    /** The distinct attribute areas a preset sets, as short dotted paths (depth 3, at most 12). @return list<string> */
    private static function controls( mixed $attrs ): array
    {
        $paths = [];
        if ( is_array( $attrs ) ) {
            self::collectPaths( $attrs, '', 0, $paths );
        }
        $paths = array_values( array_unique( $paths ) );
        sort( $paths );

        return array_map( [ self::class, 'clip' ], array_slice( $paths, 0, self::MAX_CONTROLS ) );
    }

    /** @param array<mixed> $node @param list<string> $paths */
    private static function collectPaths( array $node, string $prefix, int $depth, array &$paths ): void
    {
        foreach ( $node as $key => $value ) {
            if ( ! is_string( $key ) ) {
                continue;
            }
            $path = '' === $prefix ? $key : $prefix . '.' . $key;
            if ( is_array( $value ) && [] !== $value && $depth + 1 < self::CONTROL_DEPTH ) {
                self::collectPaths( $value, $path, $depth + 1, $paths );
            } else {
                $paths[] = $path;
            }
        }
    }

    /** The scalar settings of a preset's renderAttrs as `key=value` (at most 5), e.g. headingLevel=h3. @return list<string> */
    private static function renders( mixed $renderAttrs ): array
    {
        $out = [];
        if ( is_array( $renderAttrs ) ) {
            $walk = static function ( array $node ) use ( &$walk, &$out ): void {
                foreach ( $node as $key => $value ) {
                    if ( is_array( $value ) ) {
                        $walk( $value );
                    } elseif ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) ) {
                        $out[] = $key . '=' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
                    }
                }
            };
            $walk( $renderAttrs );
        }

        return array_map( [ self::class, 'clip' ], array_slice( array_values( array_unique( $out ) ), 0, self::MAX_RENDERS ) );
    }

    /** @param array<mixed> $data @return list<array<string,string>> */
    private static function colorList( array $data ): array
    {
        $out = [];
        foreach ( $data as $id => $color ) {
            if ( ! is_string( $id ) || ! is_array( $color ) || ! is_string( $color['color'] ?? null ) ) {
                continue;
            }
            if ( 'active' !== ( $color['status'] ?? 'active' ) ) {
                continue;
            }
            $out[] = [
                'id'     => $id,
                'label'  => self::clip( is_string( $color['label'] ?? null ) ? $color['label'] : '' ),
                'color'  => self::clip( $color['color'] ),
                'source' => self::THEME_FOLDER === ( $color['folder'] ?? '' ) ? 'theme' : 'custom',
            ];
        }
        usort( $out, static fn ( array $a, array $b ): int => [ 'theme' === $a['source'] ? 0 : 1, $a['label'], $a['id'] ] <=> [ 'theme' === $b['source'] ? 0 : 1, $b['label'], $b['id'] ] );

        return $out;
    }

    /** @param array<mixed> $data @return array<string,list<array<string,string>>> */
    private static function variableLists( array $data ): array
    {
        $out = [];
        foreach ( $data as $type => $items ) {
            $items = is_object( $items ) ? get_object_vars( $items ) : $items;
            if ( ! is_string( $type ) || ! is_array( $items ) ) {
                continue;
            }
            $list = [];
            foreach ( $items as $id => $item ) {
                if ( ! is_string( $id ) || ! is_array( $item ) || ! is_scalar( $item['value'] ?? null ) || 'active' !== ( $item['status'] ?? 'active' ) ) {
                    continue;
                }
                $list[] = [
                    'id'    => $id,
                    'label' => self::clip( is_string( $item['label'] ?? null ) ? $item['label'] : '' ),
                    'value' => self::clip( (string) $item['value'] ),
                ];
            }
            if ( [] !== $list ) {
                usort( $list, static fn ( array $a, array $b ): int => [ $a['label'], $a['id'] ] <=> [ $b['label'], $b['id'] ] );
                $out[ $type ] = $list;
            }
        }
        ksort( $out );

        return $out;
    }

    /** Cuts a value to MAX_VALUE_CHARS characters (not bytes); a value that is not valid UTF-8 is replaced, because it would make the whole JSON response fail. */
    private static function clip( string $v ): string
    {
        if ( 1 !== preg_match( '//u', $v ) ) {
            return '[value is not valid UTF-8]';
        }
        if ( strlen( $v ) <= self::MAX_VALUE_CHARS ) {
            return $v; // at most 200 bytes is at most 200 characters
        }
        $chars = preg_split( '//u', $v, self::MAX_VALUE_CHARS + 1, PREG_SPLIT_NO_EMPTY );
        if ( false === $chars || count( $chars ) <= self::MAX_VALUE_CHARS ) {
            return $v;
        }

        return implode( '', array_slice( $chars, 0, self::MAX_VALUE_CHARS ) ) . '…';
    }
}
