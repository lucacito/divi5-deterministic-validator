<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a manage_global_variables request into a change list, or refuses the WHOLE request (variables are applied
 * atomically or not at all) with one message naming every bad entry. Pure: it reads nothing from Divi itself, the
 * caller passes the current state (GlobalVariables::read()), so it can be tested without Divi.
 */
final class GlobalVariablePlanner
{
    public const MAX_ENTRIES = 50;

    private const KEYS = [ 'id', 'type', 'label', 'value', 'status' ];

    /**
     * @param list<mixed> $entries
     * @return list<array{id:?string,type:?string,label:?string,value:?string,status:?string}>
     */
    public static function normalize( array $entries ): array
    {
        $out    = [];
        $errors = [];
        foreach ( $entries as $i => $e ) {
            $where = "variables[{$i}]";
            if ( ! is_array( $e ) || ( [] !== $e && array_is_list( $e ) ) ) {
                $errors[] = "{$where} must be an object like {\"type\":\"numbers\",\"label\":\"Card radius\",\"value\":\"12px\"}.";
                continue;
            }
            foreach ( array_keys( $e ) as $k ) {
                if ( ! in_array( $k, self::KEYS, true ) ) {
                    $errors[] = "{$where}: unknown field \"{$k}\" (allowed: id, type, label, value, status).";
                }
            }
            $row = [ 'id' => null, 'type' => null, 'label' => null, 'value' => null, 'status' => null ];
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
            if ( null !== $row['id'] && ! GlobalVariables::validId( $row['id'] ) ) {
                $errors[] = "{$where}: \"id\" must look like gvid-... (letters, digits and hyphens, as list_global_styles shows it).";
            }
            if ( null !== $row['type'] && ! in_array( $row['type'], GlobalVariables::MANAGED, true ) ) {
                $errors[] = "{$where}: \"type\" must be numbers or strings (the other kinds of variable cannot be written yet).";
            }
            if ( null !== $row['label'] ) {
                $clean = GlobalColors::cleanLabel( $row['label'] );
                if ( null === $clean || empty( $clean ) ) { // Divi drops a variable whose label is empty() in PHP's sense, so "0" too
                    $errors[] = "{$where}: \"label\" must be 1 to " . GlobalColors::MAX_LABEL . ' characters, not "0", without angle brackets or control characters.';
                } else {
                    $row['label'] = $clean;
                }
            }
            if ( null !== $row['value'] ) {
                $row['value'] = trim( $row['value'] );
            }
            if ( null !== $row['status'] && ! in_array( $row['status'], GlobalVariables::STATUSES, true ) ) {
                $errors[] = "{$where}: \"status\" must be active or archived.";
            }
            if ( null === $row['id'] ) {
                if ( null === $row['type'] || null === $row['label'] || null === $row['value'] ) {
                    $errors[] = "{$where}: creating a variable needs \"type\", \"label\" and \"value\" (or give \"id\" to change an existing one).";
                } elseif ( in_array( $row['type'], GlobalVariables::MANAGED, true ) && ! GlobalVariables::valueFits( $row['type'], $row['value'] ) ) {
                    $errors[] = "{$where}: " . self::valueRule( $row['type'] );
                }
            } else {
                if ( null !== $row['type'] ) {
                    $errors[] = "{$where}: a variable never changes type; leave \"type\" out when you give an \"id\".";
                }
                if ( null === $row['label'] && null === $row['value'] && null === $row['status'] ) {
                    $errors[] = "{$where}: changing a variable needs at least one of label, value, status.";
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
     * @param array<string,array<string,array<string,mixed>>> $state GlobalVariables::read()
     * @param list<array{id:?string,type:?string,label:?string,value:?string,status:?string}> $entries normalize() output
     * @param \Closure():string $newId makes the id of a created variable
     * @return array{changes:list<array<string,mixed>>, unchanged:list<string>}
     */
    public static function plan( array $state, array $entries, \Closure $newId ): array
    {
        $changes   = [];
        $unchanged = [];
        $errors    = [];
        $seen      = [];
        $labels    = [];
        $next      = [];
        foreach ( $entries as $i => $e ) {
            $where = "variables[{$i}]";
            if ( null === $e['id'] ) {
                $type = (string) $e['type'];
                $key  = $type . '|' . mb_strtolower( (string) $e['label'] );
                if ( isset( $labels[ $key ] ) ) {
                    $errors[] = "{$where}: another new {$type} variable in this call is already named \"{$e['label']}\".";
                    continue;
                }
                $labels[ $key ] = true;
                $next[ $type ]  ??= GlobalVariables::nextOrder( $state[ $type ] ?? [] );
                $entry          = [ 'label' => (string) $e['label'], 'value' => (string) $e['value'], 'order' => (string) $next[ $type ]++, 'status' => $e['status'] ?? 'active' ];
                $changes[]      = [ 'index' => $i, 'id' => (string) $newId(), 'type' => $type, 'action' => 'create', 'fields' => [ 'label', 'value', 'status' ], 'before' => null, 'after' => GlobalVariables::state( $entry ), 'write' => $entry ];
                continue;
            }
            $id = $e['id'];
            if ( isset( $seen[ $id ] ) ) {
                $errors[] = "{$where}: the id {$id} is already in this call.";
                continue;
            }
            $seen[ $id ] = true;
            $found       = GlobalVariables::find( $state, $id );
            if ( null === $found ) {
                $errors[] = "{$where}: no variable with the id {$id} (list_global_styles shows the ids; to add a variable leave \"id\" out).";
                continue;
            }
            if ( ! in_array( $found['type'], GlobalVariables::MANAGED, true ) ) {
                $errors[] = "{$where}: {$id} is a {$found['type']} variable; only numbers and strings variables can be changed here yet.";
                continue;
            }
            if ( null !== $e['value'] && ! GlobalVariables::valueFits( $found['type'], $e['value'] ) ) {
                $errors[] = "{$where}: " . self::valueRule( $found['type'] );
                continue;
            }
            $before = (array) GlobalVariables::state( $found['entry'] );
            $after  = $before;
            $fields = [];
            foreach ( [ 'label', 'value', 'status' ] as $f ) {
                if ( null !== $e[ $f ] && $e[ $f ] !== $before[ $f ] ) {
                    $after[ $f ] = $e[ $f ];
                    $fields[]    = $f;
                }
            }
            if ( [] === $fields ) {
                $unchanged[] = $id;
                continue;
            }
            $write = $found['entry'];
            foreach ( $fields as $f ) {
                $write[ $f ] = $after[ $f ];
            }
            $action    = [ 'status' ] === $fields ? ( 'archived' === $after['status'] ? 'archive' : 'restore' ) : 'update';
            $changes[] = [ 'index' => $i, 'id' => $id, 'type' => $found['type'], 'action' => $action, 'fields' => $fields, 'before' => $before, 'after' => $after, 'write' => $write ];
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return [ 'changes' => $changes, 'unchanged' => $unchanged ];
    }

    private static function valueRule( string $type ): string
    {
        return 'numbers' === $type
            ? '"value" of a numbers variable must be a number with an optional unit (px, rem, em, %, vh, vw, vmin, vmax, ch, svh, dvh, lvh) or calc(), clamp(), min() or max() of those (write a space on both sides of + and -); var(), url(), !important and anything else are refused.'
            : '"value" of a strings variable must be 1 to ' . GlobalVariables::MAX_STRING . ' characters of plain text without braces, semicolons, backslashes, angle brackets, quotes, comment markers (/* */), url(, @import, expression( or $variable(; brackets ( ) [ ] must be balanced.';
    }
}
