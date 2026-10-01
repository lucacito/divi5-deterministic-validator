<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Describes what an edit would change, block by block. Information only: it never affects what a
 * real write does. Blocks are compared by child index under the same parent, so an insertion in
 * the middle of a list is reported as changes at the shifted indices (no move detection).
 */
final class LayoutDiff
{
    public const MAX_CHANGES         = 50;
    public const MAX_ATTRS_PER_BLOCK = 25;
    public const MAX_VALUE_CHARS     = 200;

    /** Attributes the AI re-stamps on every layout: a difference there is not a change. */
    private const IGNORED_ATTRS = [ 'builderVersion' ];

    /**
     * @return array{diff: ?array<string,mixed>, diff_error?: string}
     */
    public static function compare( string $before, string $after ): array
    {
        $parser = new BlockParser();

        $a = $parser->parse( $before );
        if ( ! $a->isOk() ) {
            return [ 'diff' => null, 'diff_error' => 'The current page content could not be parsed: ' . self::firstError( $a->errors() ) ];
        }
        $b = $parser->parse( $after );
        if ( ! $b->isOk() ) {
            return [ 'diff' => null, 'diff_error' => 'The new layout could not be parsed: ' . self::firstError( $b->errors() ) ];
        }

        $state = [ 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0, 'changes' => [] ];
        self::compareChildren( $a->root(), $b->root(), '', $state );

        $total = $state['added'] + $state['removed'] + $state['changed'];

        return [
            'diff' => [
                'summary'   => [
                    'added'     => $state['added'],
                    'removed'   => $state['removed'],
                    'changed'   => $state['changed'],
                    'unchanged' => $state['unchanged'],
                ],
                'changes'   => $state['changes'],
                'truncated' => $total > self::MAX_CHANGES,
            ],
        ];
    }

    /** @param array<string,mixed> $state */
    private static function compareChildren( Block $a, Block $b, string $parentPath, array &$state ): void
    {
        $ca = $a->children();
        $cb = $b->children();
        $n  = max( count( $ca ), count( $cb ) );

        for ( $i = 0; $i < $n; $i++ ) {
            $x = $ca[ $i ] ?? null;
            $y = $cb[ $i ] ?? null;

            if ( null !== $x && null === $y ) {
                self::record( $state, 'removed', $x->name(), self::path( $parentPath, $x->name(), $i ) );
                continue;
            }
            if ( null === $x && null !== $y ) {
                self::record( $state, 'added', $y->name(), self::path( $parentPath, $y->name(), $i ) );
                continue;
            }
            if ( null === $x || null === $y ) {
                continue; // unreachable: $i < max(count) means at least one side exists
            }
            if ( $x->name() !== $y->name() ) {
                self::record( $state, 'removed', $x->name(), self::path( $parentPath, $x->name(), $i ) );
                self::record( $state, 'added', $y->name(), self::path( $parentPath, $y->name(), $i ) );
                continue;
            }

            $path  = self::path( $parentPath, $x->name(), $i );
            $attrs = self::attrDifferences( $x->attrs(), $y->attrs() );
            if ( [] === $attrs['diffs'] ) {
                ++$state['unchanged'];
            } else {
                $extra = [ 'attrs' => $attrs['diffs'] ];
                if ( $attrs['omitted'] > 0 ) {
                    $extra['attrs_omitted'] = $attrs['omitted'];
                }
                self::record( $state, 'changed', $x->name(), $path, $extra );
            }
            self::compareChildren( $x, $y, $path, $state );
        }
    }

    /**
     * Counts every change, but keeps the details of only the first MAX_CHANGES so a rewrite of a
     * huge page cannot flood the AI's context.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $extra
     */
    private static function record( array &$state, string $kind, string $block, string $path, array $extra = [] ): void
    {
        ++$state[ $kind ];
        if ( count( $state['changes'] ) < self::MAX_CHANGES ) {
            $state['changes'][] = [ 'path' => $path, 'block' => $block, 'kind' => $kind ] + $extra;
        }
    }

    private static function path( string $parentPath, string $name, int $index ): string
    {
        return ( '' === $parentPath ? '' : $parentPath . '/' ) . $name . '[' . $index . ']';
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array{diffs: list<array{path:string, before:?string, after:?string}>, omitted:int}
     */
    private static function attrDifferences( array $a, array $b ): array
    {
        $fa = [];
        $fb = [];
        self::flatten( self::withoutIgnored( $a ), '', $fa );
        self::flatten( self::withoutIgnored( $b ), '', $fb );

        $paths   = array_values( array_unique( array_map( 'strval', array_merge( array_keys( $fa ), array_keys( $fb ) ) ) ) );
        $diffs   = [];
        $omitted = 0;
        foreach ( $paths as $path ) {
            $va = $fa[ $path ] ?? null;
            $vb = $fb[ $path ] ?? null;
            if ( $va === $vb ) {
                continue;
            }
            if ( count( $diffs ) < self::MAX_ATTRS_PER_BLOCK ) {
                $diffs[] = [ 'path' => $path, 'before' => self::clip( $va ), 'after' => self::clip( $vb ) ];
            } else {
                ++$omitted;
            }
        }

        return [ 'diffs' => $diffs, 'omitted' => $omitted ];
    }

    /**
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private static function withoutIgnored( array $attrs ): array
    {
        return array_diff_key( $attrs, array_flip( self::IGNORED_ATTRS ) );
    }

    /**
     * Flattens nested attributes to dot-paths with string leaves.
     *
     * @param array<mixed> $node
     * @param array<string,string> $out
     */
    private static function flatten( array $node, string $prefix, array &$out ): void
    {
        if ( [] === $node && '' !== $prefix ) {
            $out[ $prefix ] = '[]';

            return;
        }
        foreach ( $node as $key => $value ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) ) {
                self::flatten( $value, $path, $out );
            } else {
                $out[ $path ] = self::scalar( $value );
            }
        }
    }

    private static function scalar( mixed $v ): string
    {
        if ( is_string( $v ) ) {
            return $v;
        }
        if ( null === $v ) {
            return 'null';
        }
        if ( is_bool( $v ) ) {
            return $v ? 'true' : 'false';
        }

        return (string) $v;
    }

    /** Cuts a value to MAX_VALUE_CHARS characters (not bytes), marking the cut with an ellipsis. */
    private static function clip( ?string $v ): ?string
    {
        if ( null === $v || strlen( $v ) <= self::MAX_VALUE_CHARS ) {
            return $v; // at most 200 bytes is at most 200 characters
        }
        $chars = preg_split( '//u', $v, self::MAX_VALUE_CHARS + 1, PREG_SPLIT_NO_EMPTY );
        if ( false === $chars ) {
            return '[value is not valid UTF-8]'; // cannot happen for json_decode()d attributes
        }
        if ( count( $chars ) <= self::MAX_VALUE_CHARS ) {
            return $v;
        }

        return implode( '', array_slice( $chars, 0, self::MAX_VALUE_CHARS ) ) . '…';
    }

    /** @param list<string> $errors */
    private static function firstError( array $errors ): string
    {
        return (string) ( $errors[0] ?? 'unknown error' );
    }
}
