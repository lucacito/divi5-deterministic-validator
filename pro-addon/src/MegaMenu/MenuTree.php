<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A WordPress menu as a tree. Pure: it takes the flat list WordPress hands out and returns nested arrays, so the layouts
 * can be tested without WordPress.
 *
 * An item is: id, title, url, newTab, description, type (post_type | taxonomy | post_type_archive | custom ...),
 * object (page, post, category, project ...), objectId, count (published posts of a category, tag or archive item, else null), children.
 */
final class MenuTree
{
    /**
     * @param list<array<string,mixed>> $flat items with id, parent, order and the fields above (parent 0 = top level)
     * @return list<array<string,mixed>>
     */
    public static function build( array $flat ): array
    {
        $byParent = [];
        foreach ( $flat as $item ) {
            $byParent[ (int) ( $item['parent'] ?? 0 ) ][] = $item;
        }
        foreach ( $byParent as &$siblings ) {
            usort( $siblings, static fn ( array $a, array $b ): int => ( (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ) ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
        }
        unset( $siblings );

        // An item whose parent is not in the list is treated as top level, so a broken menu still shows.
        $ids = [];
        foreach ( $flat as $item ) {
            $ids[ (int) $item['id'] ] = true;
        }
        $roots = [];
        foreach ( $byParent as $parent => $siblings ) {
            if ( 0 === $parent || ! isset( $ids[ $parent ] ) ) {
                array_push( $roots, ...$siblings );
            }
        }
        usort( $roots, static fn ( array $a, array $b ): int => ( (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ) ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );

        $seen = [];
        $make = static function ( array $item ) use ( &$make, $byParent, &$seen ): array {
            $id          = (int) $item['id'];
            $seen[ $id ] = true;
            $children    = [];
            foreach ( $byParent[ $id ] ?? [] as $child ) {
                if ( ! isset( $seen[ (int) $child['id'] ] ) ) { // a parent loop never recurses forever
                    $children[] = $make( $child );
                }
            }

            return [
                'id'          => $id,
                'title'       => (string) ( $item['title'] ?? '' ),
                'url'         => (string) ( $item['url'] ?? '' ),
                'newTab'      => (bool) ( $item['newTab'] ?? false ),
                'description' => (string) ( $item['description'] ?? '' ),
                'type'        => (string) ( $item['type'] ?? 'custom' ),
                'object'      => (string) ( $item['object'] ?? '' ),
                'objectId'    => (int) ( $item['objectId'] ?? 0 ),
                'count'       => isset( $item['count'] ) ? (int) $item['count'] : null,
                'children'    => $children,
            ];
        };

        return array_map( $make, $roots );
    }

    /** Number of top-level items that have sub-items (the ones that get a panel). */
    public static function panelCount( array $tree ): int
    {
        return count( array_filter( $tree, static fn ( array $i ): bool => [] !== $i['children'] ) );
    }
}
