<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A fingerprint of what a panel IS, not of how it is written down: the block names, their nesting and every attribute, with the
 * attributes in a fixed key order and `builderVersion` left out. Saving the header again in the Divi builder rewrites the markup (key
 * order, escaping, white space, the builder's version) without changing the panel, and that must not look like an edit; changing a
 * text, a colour or a module must. Pure.
 *
 * It is how an update tells a panel the owner changed by hand from one it wrote itself.
 */
final class PanelFingerprint
{
    /** An opening, closing or self-closing Divi block comment: closer (1), name (2), attribute JSON (3), self-closing (4). */
    private const COMMENT = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{.*?\}))?\s*(\/?)-->/s';

    /** Attributes that change when the header is merely saved again. */
    private const IGNORED = [ 'builderVersion' ];

    public static function of( string $markup ): string
    {
        $parts = [];
        if ( false === preg_match_all( self::COMMENT, $markup, $all, PREG_SET_ORDER ) ) {
            return '';
        }
        foreach ( $all as $m ) {
            if ( '/' === $m[1] ) {
                $parts[] = '</' . $m[2];
                continue;
            }
            $attrs = isset( $m[3] ) && '' !== $m[3] ? json_decode( $m[3], true ) : [];
            if ( is_array( $attrs ) ) {
                foreach ( self::IGNORED as $key ) {
                    unset( $attrs[ $key ] );
                }
                if ( 'divi/dropdown' === $m[2] ) {
                    unset( $attrs['module']['meta']['adminLabel'] ); // the label carries the fingerprint itself
                }
            } else {
                $attrs = [];
            }
            $parts[] = ( '/' === $m[4] ? '<' : '>' ) . $m[2] . ' ' . json_encode( self::sorted( $attrs ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        return sha1( implode( "\n", $parts ) );
    }

    /** The first ten characters of the fingerprint: what a panel's label carries. */
    public static function short( string $markup ): string
    {
        return substr( self::of( $markup ), 0, 10 );
    }

    /** The fingerprint written in the panel's own label (the last word of "JHMG panel <item> <layout> <fingerprint>"), or null when it has none. */
    public static function stamped( string $markup ): ?string
    {
        if ( 1 === preg_match( '/wp:divi\/dropdown\s+\{.*?"adminLabel":\{"desktop":\{"value":"JHMG panel \d+ [a-z0-9-]+ ([0-9a-f]{10})"/s', $markup, $m ) ) {
            return $m[1];
        }

        return null;
    }

    /** Whether the panel differs from what was written: it has a fingerprint and the fingerprint no longer fits. A panel without one (an older header) is never called edited. */
    public static function edited( string $markup ): bool
    {
        $stamp = self::stamped( $markup );

        return null !== $stamp && self::short( $markup ) !== $stamp;
    }

    /** Keys of every object in order; lists keep their order. */
    private static function sorted( mixed $v ): mixed
    {
        if ( ! is_array( $v ) ) {
            return $v;
        }
        $isList = array_is_list( $v );
        $v      = array_map( [ self::class, 'sorted' ], $v );
        if ( ! $isList ) {
            ksort( $v );
        }

        return $v;
    }
}
