<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tells whether the mega menu stored in the header still matches the site's menu. The panels are written once, when a layout
 * is applied, so a menu edited afterwards leaves them behind. Pure: it reads the links and labels out of two pieces of block
 * markup (the stored header and the same header built again from the menu as it is now, by the same code) and compares them.
 *
 * What is compared, per panel (and for the top-level bar): the Links (text and address), the Text modules (labels,
 * descriptions) and the Headings. Anything the builder wrote from the menu is in both; anything that only the stored
 * copy has (the newest posts of a "latest" layout are a snapshot) is not counted as drift for those panels.
 */
final class MenuDrift
{
    /** An opening, closing or self-closing Divi block comment: closer (1), name (2), attribute JSON (3), self-closing (4). */
    private const COMMENT = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{.*?\}))?\s*(\/?)-->/s';

    /** Key of the top-level bar in a signature. */
    public const TOP = 0;

    /**
     * Every Link, Text and Heading of the wrapper, grouped by the panel it sits in (TOP = the bar itself).
     *
     * @return array<int,list<string>> panel id (the menu item id from the panel's label, 0 = the bar) => entries `kind<TAB>text<TAB>url`, in document order
     */
    public static function signature( string $content ): array
    {
        $out = [ self::TOP => [] ];
        if ( false === preg_match_all( self::COMMENT, $content, $all, PREG_SET_ORDER ) ) {
            return $out;
        }
        /** @var list<array{string,?int}> $stack */
        $stack = [];
        foreach ( $all as $m ) {
            if ( '/' === $m[1] ) {
                array_pop( $stack );
                continue;
            }
            $name  = $m[2];
            $attrs = isset( $m[3] ) && '' !== $m[3] ? json_decode( $m[3], true ) : [];
            $attrs = is_array( $attrs ) ? $attrs : [];
            $panel = self::TOP;
            foreach ( $stack as $open ) {
                if ( null !== $open[1] ) {
                    $panel = $open[1];
                }
            }
            $skipped = false;
            foreach ( $stack as $open ) {
                $skipped = $skipped || 'skip' === $open[0];
            }
            if ( $skipped ) {
                // inside a post card or a count pill: a snapshot, not part of the menu
            } elseif ( 'divi/link' === $name ) {
                $v                = $attrs['content']['innerContent']['desktop']['value'] ?? [];
                $out[ $panel ][]  = self::entry( 'link', (string) ( is_array( $v ) ? ( $v['text'] ?? '' ) : '' ), (string) ( is_array( $v ) ? ( $v['linkUrl'] ?? '' ) : '' ) );
            } elseif ( 'divi/text' === $name ) {
                $out[ $panel ][] = self::entry( 'text', (string) ( $attrs['content']['innerContent']['desktop']['value'] ?? '' ), '' );
            } elseif ( 'divi/heading' === $name ) {
                $out[ $panel ][] = self::entry( 'heading', (string) ( $attrs['title']['innerContent']['desktop']['value'] ?? '' ), '' );
            }
            if ( '/' !== $m[4] ) {
                $id = null;
                if ( 'divi/dropdown' === $name ) {
                    $label = $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? null;
                    $id    = is_string( $label ) && 1 === preg_match( '/^JHMG panel (\d+) /', $label, $l ) ? (int) $l[1] : -1;
                    $out[ $id ] ??= [];
                }
                $isPost  = 'divi/group' === $name && in_array( $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? null, [ Blocks::POST_LABEL, Blocks::COUNT_LABEL ], true );
                $stack[] = [ $isPost ? 'skip' : $name, $id ];
            }
        }

        return $out;
    }

    /**
     * What differs between the stored wrapper and the one the menu gives now.
     *
     * @param array<int,list<string>> $stored    signature() of the stored header
     * @param array<int,list<string>> $fresh     signature() of the header built from the current menu
     * @param list<int>               $snapshots panels whose layout shows a snapshot of posts: only what the menu is missing counts there
     * @return array<int,array{missing:list<string>,extra:list<string>,reordered:bool}> only panels that differ; TOP = the bar
     */
    public static function compare( array $stored, array $fresh, array $snapshots = [] ): array
    {
        $out = [];
        foreach ( array_unique( array_merge( array_keys( $stored ), array_keys( $fresh ) ) ) as $panel ) {
            $have    = $stored[ $panel ] ?? [];
            $want    = $fresh[ $panel ] ?? [];
            $missing = self::minus( $want, $have );
            $extra   = in_array( $panel, $snapshots, true ) ? [] : self::minus( $have, $want );
            $reorder = [] === $missing && [] === $extra && ! in_array( $panel, $snapshots, true ) && $have !== $want;
            if ( [] !== $missing || [] !== $extra || $reorder ) {
                $out[ $panel ] = [ 'missing' => self::names( $missing ), 'extra' => self::names( $extra ), 'reordered' => $reorder ];
            }
        }
        ksort( $out );

        return $out;
    }

    /** The readable part of an entry: its text (a link or label), or the address when there is no text. */
    public static function names( array $entries ): array
    {
        $out = [];
        foreach ( $entries as $e ) {
            [ , $text, $url ] = explode( "\t", $e ) + [ 2 => '', 1 => '' ];
            $out[]            = '' !== $text ? $text : $url;
        }

        return $out;
    }

    private static function entry( string $kind, string $text, string $url ): string
    {
        $text = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );

        return $kind . "\t" . $text . "\t" . trim( $url );
    }

    /** The entries of `$a` that `$b` does not have (a repeated entry counts as often as it repeats). @return list<string> */
    private static function minus( array $a, array $b ): array
    {
        $count = array_count_values( $b );
        $out   = [];
        foreach ( $a as $x ) {
            if ( ( $count[ $x ] ?? 0 ) > 0 ) {
                --$count[ $x ];
            } else {
                $out[] = $x;
            }
        }

        return $out;
    }
}
