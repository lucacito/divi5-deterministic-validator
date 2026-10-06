<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

use AiEditorDivi5\Pro\Canon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a manage_global_presets request into a change list, or refuses the WHOLE request (presets are applied atomically or not at
 * all) with one message naming every bad entry. Pure: it reads nothing from Divi itself, the caller passes the current data.
 */
final class GlobalPresetPlanner
{
    public const MAX_ENTRIES = 20;

    private const KEYS = [ 'id', 'module', 'name', 'attrs' ];

    /**
     * @param list<mixed> $entries
     * @return list<array{id:?string,module:?string,name:?string,attrs:?array<mixed>}>
     */
    public static function normalize( array $entries ): array
    {
        $out    = [];
        $errors = [];
        foreach ( $entries as $i => $e ) {
            $where = "presets[{$i}]";
            if ( ! is_array( $e ) || ( [] !== $e && array_is_list( $e ) ) ) {
                $errors[] = "{$where} must be an object like {\"module\":\"divi/heading\",\"name\":\"Big heading\",\"attrs\":{...}}.";
                continue;
            }
            foreach ( array_keys( $e ) as $k ) {
                if ( ! in_array( $k, self::KEYS, true ) ) {
                    $errors[] = "{$where}: unknown field \"{$k}\" (allowed: id, module, name, attrs).";
                }
            }
            $row = [ 'id' => null, 'module' => null, 'name' => null, 'attrs' => null ];
            foreach ( [ 'id', 'module', 'name' ] as $k ) {
                if ( ! array_key_exists( $k, $e ) || null === $e[ $k ] ) {
                    continue;
                }
                if ( ! is_string( $e[ $k ] ) ) {
                    $errors[] = "{$where}: \"{$k}\" must be a string.";
                    continue;
                }
                $row[ $k ] = $e[ $k ];
            }
            if ( array_key_exists( 'attrs', $e ) && null !== $e['attrs'] ) {
                if ( ! is_array( $e['attrs'] ) ) {
                    $errors[] = "{$where}: \"attrs\" must be an object (the style tree).";
                } else {
                    $row['attrs'] = $e['attrs'];
                }
            }
            if ( null !== $row['id'] && ! GlobalPresets::validId( $row['id'] ) ) {
                $errors[] = "{$where}: \"id\" must be 8 to 64 letters, digits and hyphens (as list_global_styles shows it).";
            }
            if ( null !== $row['module'] && ! GlobalPresets::validModule( $row['module'] ) ) {
                $errors[] = "{$where}: \"module\" must be a Divi 5 module such as divi/heading.";
            }
            if ( null !== $row['name'] ) {
                $clean = GlobalColors::cleanLabel( $row['name'] );
                if ( null === $clean ) {
                    $errors[] = "{$where}: \"name\" must be 1 to " . GlobalColors::MAX_LABEL . ' characters, without angle brackets or control characters.';
                } else {
                    $row['name'] = $clean;
                }
            }
            if ( null === $row['id'] ) {
                if ( null === $row['module'] || null === $row['name'] || null === $row['attrs'] ) {
                    $errors[] = "{$where}: creating a preset needs \"module\", \"name\" and \"attrs\" (or give \"id\" to change an existing one).";
                } elseif ( GlobalPresets::validModule( $row['module'] ) ) {
                    foreach ( GlobalPresets::attrsProblems( $row['module'], $row['attrs'] ) as $problem ) {
                        $errors[] = "{$where}: {$problem}.";
                    }
                }
            } else {
                if ( null !== $row['module'] ) {
                    $errors[] = "{$where}: a preset never moves to another module; leave \"module\" out when you give an \"id\".";
                }
                if ( null === $row['name'] && null === $row['attrs'] ) {
                    $errors[] = "{$where}: changing a preset needs at least one of name, attrs.";
                }
            }
            $out[] = $row;
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data GlobalPresets::read()
     * @param list<array{id:?string,module:?string,name:?string,attrs:?array<mixed>}> $rows normalize() output
     * @param \Closure():string $newId makes the id of a created preset
     * @return array{changes:list<array<string,mixed>>, unchanged:list<string>}
     */
    public static function plan( array $data, array $rows, bool $allowDefault, int $now, \Closure $newId ): array
    {
        $changes   = [];
        $unchanged = [];
        $errors    = [];
        $seen      = [];
        $names     = [];
        foreach ( $rows as $i => $r ) {
            $where = "presets[{$i}]";
            if ( null === $r['id'] ) {
                $module = (string) $r['module'];
                $key    = $module . '|' . mb_strtolower( (string) $r['name'] );
                if ( isset( $names[ $key ] ) ) {
                    $errors[] = "{$where}: another new {$module} preset in this call is already named \"{$r['name']}\".";
                    continue;
                }
                $names[ $key ] = true;
                $id            = (string) $newId();
                $item          = GlobalPresets::newItem( $module, $id, (string) $r['name'], (array) $r['attrs'], $now );
                $existing      = GlobalPresets::itemIds( $data, $module );
                $changes[]     = [
                    'index' => $i, 'id' => $id, 'module' => $module, 'action' => 'create', 'before' => null, 'after' => GlobalPresets::state( $item ),
                    'item' => $item, 'is_default' => false, 'default_created' => [] === $existing, 'module_existed' => [] !== $existing,
                ];
                continue;
            }
            $id = $r['id'];
            if ( isset( $seen[ $id ] ) ) {
                $errors[] = "{$where}: the id {$id} is already in this call.";
                continue;
            }
            $seen[ $id ] = true;
            $found       = GlobalPresets::find( $data, $id );
            if ( null === $found ) {
                $errors[] = "{$where}: no preset with the id {$id} (list_global_styles shows the ids; to add a preset leave \"id\" out).";
                continue;
            }
            if ( ! GlobalPresets::managed( $found['module'], $found['item'] ) ) {
                $errors[] = "{$where}: {$id} cannot be changed here (it is not a regular Divi 5 module preset).";
                continue;
            }
            $isDefault = GlobalPresets::defaultId( $data, $found['module'] ) === $id;
            if ( $isDefault && ! $allowDefault ) {
                $errors[] = "{$where}: {$id} is the default preset of {$found['module']}; changing it restyles every {$found['module']} that has no preset of its own. Pass allow_default_presets=true to do that on purpose.";
                continue;
            }
            if ( null !== $r['attrs'] ) {
                foreach ( GlobalPresets::attrsProblems( $found['module'], $r['attrs'] ) as $problem ) {
                    $errors[] = "{$where}: {$problem}.";
                }
            }
            $before = GlobalPresets::state( $found['item'] );
            $item   = $found['item'];
            if ( null !== $r['name'] ) {
                $item['name'] = $r['name'];
            }
            if ( null !== $r['attrs'] ) {
                $item['attrs']      = $r['attrs'];
                $item['styleAttrs'] = $r['attrs'];
            }
            $after = GlobalPresets::state( $item );
            if ( Canon::hash( $before ) === Canon::hash( $after ) ) {
                $unchanged[] = $id;
                continue;
            }
            $item['updated'] = $now;
            $changes[]       = [
                'index' => $i, 'id' => $id, 'module' => $found['module'], 'action' => 'update', 'before' => $before, 'after' => $after,
                'item' => $item, 'is_default' => $isDefault, 'default_created' => false, 'module_existed' => true,
            ];
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return [ 'changes' => $changes, 'unchanged' => $unchanged ];
    }
}
