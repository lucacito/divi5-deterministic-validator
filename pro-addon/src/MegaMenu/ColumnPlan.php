<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * How the sub-items of one top-level menu item are shared out over link columns. Pure.
 *
 * A child that has children of its own is a heading with links under it; a child without is a loose link. Loose links
 * form one headless section that comes first. Nothing is ever dropped: when there are more sections than columns, the
 * extra sections are stacked under the last column; when one headless section would fill one column it is cut evenly
 * over the columns.
 */
final class ColumnPlan
{
    /**
     * @param array<string,mixed> $item a top-level menu item (see MenuTree)
     * @return list<list<array{heading:?array<string,mixed>,links:list<array<string,mixed>>}>> columns, each a list of sections
     */
    public static function from( array $item, int $maxColumns ): array
    {
        $maxColumns = max( 1, $maxColumns );
        $loose      = [];
        $groups     = [];
        foreach ( $item['children'] as $child ) {
            if ( [] === $child['children'] ) {
                $loose[] = $child;
            } else {
                $groups[] = [ 'heading' => $child, 'links' => self::flatten( $child['children'] ) ];
            }
        }
        $sections = [];
        if ( [] !== $loose ) {
            $sections[] = [ 'heading' => null, 'links' => $loose ];
        }
        foreach ( $groups as $g ) {
            $sections[] = $g;
        }
        if ( [] === $sections ) {
            return [];
        }

        if ( 1 === count( $sections ) && null === $sections[0]['heading'] && $maxColumns > 1 ) {
            $links = $sections[0]['links'];
            $per   = (int) ceil( count( $links ) / min( $maxColumns, count( $links ) ) );

            return array_map(
                static fn ( array $chunk ): array => [ [ 'heading' => null, 'links' => $chunk ] ],
                array_chunk( $links, max( 1, $per ) )
            );
        }

        if ( count( $sections ) <= $maxColumns ) {
            return array_map( static fn ( array $s ): array => [ $s ], $sections );
        }
        $columns = array_map( static fn ( array $s ): array => [ $s ], array_slice( $sections, 0, $maxColumns - 1 ) );
        $columns[] = array_slice( $sections, $maxColumns - 1 );

        return $columns;
    }

    /** All items of a sub-tree in reading order (parents before their children). @return list<array<string,mixed>> */
    public static function flatten( array $items ): array
    {
        $out = [];
        foreach ( $items as $it ) {
            $out[] = $it;
            array_push( $out, ...self::flatten( $it['children'] ) );
        }

        return $out;
    }
}
