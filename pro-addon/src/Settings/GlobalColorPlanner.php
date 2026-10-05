<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a manage_global_colors request into a change list, or refuses the WHOLE request (a palette is applied
 * atomically or not at all) with one message naming every bad entry. Pure: it reads nothing from Divi itself, the
 * caller passes the current state, so it can be tested without Divi.
 */
final class GlobalColorPlanner
{
    public const MAX_ENTRIES = 50;

    private const KEYS     = [ 'id', 'label', 'color', 'status' ];
    private const STATUSES = [ 'active', 'inactive' ];

    /**
     * @param list<mixed> $entries
     * @return list<array{id:?string,label:?string,color:?string,status:?string}>
     */
    public static function normalize( array $entries ): array
    {
        $out    = [];
        $errors = [];
        foreach ( $entries as $i => $e ) {
            $where = "colors[{$i}]";
            if ( ! is_array( $e ) || ( [] !== $e && array_is_list( $e ) ) ) {
                $errors[] = "{$where} must be an object like {\"label\":\"Brand\",\"color\":\"#112233\"}.";
                continue;
            }
            foreach ( array_keys( $e ) as $k ) {
                if ( ! in_array( $k, self::KEYS, true ) ) {
                    $errors[] = "{$where}: unknown field \"{$k}\" (allowed: id, label, color, status).";
                }
            }
            $row = [ 'id' => null, 'label' => null, 'color' => null, 'status' => null ];
            foreach ( self::KEYS as $k ) {
                if ( ! array_key_exists( $k, $e ) || null === $e[ $k ] ) {
                    continue;
                }
                if ( ! is_string( $e[ $k ] ) ) {
                    $errors[] = "{$where}: \"{$k}\" must be a string.";
                    continue;
                }
                $row[ $k ] = $e[ $k ];
            }
            if ( null !== $row['id'] && ! GlobalColors::validId( $row['id'] ) ) {
                $errors[] = "{$where}: \"id\" must look like gcid-... (letters, digits and hyphens, as list_global_styles shows it).";
            }
            if ( null !== $row['label'] ) {
                $clean = GlobalColors::cleanLabel( $row['label'] );
                if ( null === $clean ) {
                    $errors[] = "{$where}: \"label\" must be 1 to " . GlobalColors::MAX_LABEL . ' characters, without angle brackets or control characters.';
                } else {
                    $row['label'] = $clean;
                }
            }
            if ( null !== $row['color'] ) {
                $row['color'] = trim( $row['color'] );
                if ( ! GlobalColors::validColor( $row['color'] ) ) {
                    $errors[] = "{$where}: \"color\" must be a hex value (#rgb, #rgba, #rrggbb, #rrggbbaa) or rgb()/rgba()/hsl()/hsla() with plain numbers; named colors, var(), url() and anything else are refused.";
                }
            }
            if ( null !== $row['status'] && ! in_array( $row['status'], self::STATUSES, true ) ) {
                $errors[] = "{$where}: \"status\" must be active or inactive.";
            }
            if ( null === $row['id'] ) {
                if ( null === $row['label'] || null === $row['color'] ) {
                    $errors[] = "{$where}: creating a color needs \"label\" and \"color\" (or give \"id\" to change an existing one).";
                }
            } elseif ( null === $row['label'] && null === $row['color'] && null === $row['status'] ) {
                $errors[] = "{$where}: changing a color needs at least one of label, color, status.";
            }
            $out[] = $row;
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $state GlobalColors::read()
     * @param list<array{id:?string,label:?string,color:?string,status:?string}> $entries normalize() output
     * @param \Closure():string $newId makes the id of a created color
     * @return array{changes:list<array<string,mixed>>, unchanged:list<string>, theme_touched:bool}
     */
    public static function plan( array $state, array $entries, bool $allowTheme, string $now, \Closure $newId ): array
    {
        $changes   = [];
        $unchanged = [];
        $errors    = [];
        $seen      = [];
        $labels    = [];
        foreach ( $entries as $i => $e ) {
            $where = "colors[{$i}]";
            if ( null === $e['id'] ) {
                $key = mb_strtolower( (string) $e['label'] );
                if ( isset( $labels[ $key ] ) ) {
                    $errors[] = "{$where}: another new color in this call is already named \"{$e['label']}\".";
                    continue;
                }
                $labels[ $key ] = true;
                $entry          = [ 'color' => (string) $e['color'], 'folder' => '', 'label' => (string) $e['label'], 'lastUpdated' => $now, 'status' => $e['status'] ?? 'active', 'usedInPosts' => [] ];
                $changes[]      = [ 'index' => $i, 'id' => (string) $newId(), 'action' => 'create', 'fields' => [ 'color', 'label', 'status' ], 'before' => null, 'after' => GlobalColors::state( $entry ), 'write' => $entry, 'theme' => false ];
                continue;
            }
            $id = $e['id'];
            if ( isset( $seen[ $id ] ) ) {
                $errors[] = "{$where}: the id {$id} is already in this call.";
                continue;
            }
            $seen[ $id ] = true;
            if ( ! isset( $state[ $id ] ) ) {
                $errors[] = "{$where}: no color with the id {$id} (list_global_styles shows the ids; to add a color leave \"id\" out).";
                continue;
            }
            $current = $state[ $id ];
            $theme   = GlobalColors::isThemeId( $id );
            if ( $theme ) {
                if ( ! $allowTheme ) {
                    $errors[] = "{$where}: {$id} is one of the five theme colors. Changing it restyles the whole theme: pass allow_theme_colors=true to do that on purpose.";
                    continue;
                }
                if ( null !== $e['label'] || null !== $e['status'] ) {
                    $errors[] = "{$where}: theme colors can only change their color (Divi generates their name and status).";
                    continue;
                }
            }
            if ( 'temporary' === ( $current['status'] ?? '' ) ) {
                $errors[] = "{$where}: {$id} is a temporary color that belongs to Divi's builder session; it is left alone.";
                continue;
            }
            $before = GlobalColors::state( $current );
            $after  = $before;
            $fields = [];
            foreach ( [ 'color', 'label', 'status' ] as $f ) {
                if ( null !== $e[ $f ] && $e[ $f ] !== $before[ $f ] ) {
                    $after[ $f ] = $e[ $f ];
                    $fields[]    = $f;
                }
            }
            if ( [] === $fields ) {
                $unchanged[] = $id;
                continue;
            }
            if ( $theme ) {
                $write = GlobalColors::themeEntry( $after['color'], $before['label'], $now );
            } else {
                $write = $current;
                foreach ( $fields as $f ) {
                    $write[ $f ] = $after[ $f ];
                }
                $write['lastUpdated'] = $now;
            }
            $action    = [ 'status' ] === $fields ? ( 'inactive' === $after['status'] ? 'deactivate' : 'reactivate' ) : 'update';
            $changes[] = [ 'index' => $i, 'id' => $id, 'action' => $action, 'fields' => $fields, 'before' => $before, 'after' => $after, 'write' => $write, 'theme' => $theme ];
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return [ 'changes' => $changes, 'unchanged' => $unchanged, 'theme_touched' => [] !== array_filter( $changes, static fn ( array $c ): bool => $c['theme'] ) ];
    }
}
