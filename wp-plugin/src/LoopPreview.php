<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/CodePolicy.php';

/**
 * What the loops of a layout will list on THIS site, so a wrong post type, menu or term id is caught before the page is saved
 * instead of showing Divi's "No Results Found." afterwards. Advisory only (`advisories.loop_results`): it never changes a
 * verdict, blocks a save or writes anything; everything is read through WordPress's own public read functions.
 *
 * It checks what a site can answer with certainty: that every post type, taxonomy, term id, menu and role the loop names exists,
 * and, for a plain post or menu loop, how many items match and the first titles (compared with Divi's own render by
 * `make verify-loop-builder`). Filters (terms, specific posts, meta, search), sticky posts, `current_page`, users and attachments
 * are not counted and the row says so; it never guesses a count.
 *
 * Divi 5.15.1 facts it relies on (`QueryResultsController`, `LoopUtils`): posts are queried with status publish, terms with
 * `hide_empty` true, a menu loop lists every item of the menu (children included), "descending" being the menu's own order.
 */
final class LoopPreview
{
    public const MAX_ROWS    = 20;
    public const MAX_TITLES  = 3;
    public const DEFAULT_PER = 10;

    public const MESSAGE = "These are the loops of this layout, checked against this site. A problem means the loop will list nothing, or the wrong things, here (the text says which). found is how many items match, listed how many the loop shows on page 1; first lists the first titles. A null count means the loop uses a filter or a source this check does not count. Call get_site_structure with include=menus,terms for the menu and term ids.";

    /** Loop settings that narrow the list in ways this check does not simulate. */
    private const FILTERS = [ 'includePostWithSpecificTerms', 'excludePostWithSpecificTerms', 'includeSpecificPosts', 'excludeSpecificPosts', 'metaQuery' ];

    /** Orderings WP_Query reproduces exactly (Divi maps these keys to the same orderby). */
    private const ORDER_BY = [ 'date' => 'date', 'title' => 'title', 'modified' => 'modified', 'name' => 'name', 'ID' => 'ID', 'menu_order' => 'menu_order', 'comment_count' => 'comment_count' ];

    /**
     * @param array<string,callable> $readers see readers()
     * @return ?array{loops: list<array<string,mixed>>, message: string}
     */
    public static function advise( string $content, ?array $readers = null ): ?array
    {
        if ( ! str_contains( $content, '"loop"' ) || CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }
        $rows = [];
        self::visit( $parsed->root(), $readers ?? self::readers(), $rows );

        return [] === $rows ? null : [ 'loops' => $rows, 'message' => self::MESSAGE ];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function visit( Block $block, array $readers, array &$rows ): void
    {
        $value = $block->attr( 'module.advanced.loop.desktop.value' );
        if ( is_array( $value ) && 'on' === ( $value['enable'] ?? null ) && count( $rows ) < self::MAX_ROWS ) {
            $rows[] = self::row( $block->name(), $value, $readers );
        }
        foreach ( $block->children() as $child ) {
            self::visit( $child, $readers, $rows );
        }
    }

    /** @param array<string,mixed> $v @param array<string,callable> $r @return array<string,mixed> */
    private static function row( string $block, array $v, array $r ): array
    {
        $type  = is_string( $v['queryType'] ?? null ) ? $v['queryType'] : 'post_types';
        $row   = [ 'block' => $block, 'loop_id' => is_string( $v['loopId'] ?? null ) ? $v['loopId'] : '', 'query_type' => $type, 'problems' => [], 'found' => null, 'listed' => null, 'first' => [] ];
        $subs  = self::subValues( $v['subTypes'] ?? [] );
        $per   = self::whole( $v['postPerPage'] ?? null, self::DEFAULT_PER );
        $off   = self::whole( $v['postOffset'] ?? null, 0 );
        $note  = null;

        if ( 'post_types' === $type ) {
            $note = self::posts( $row, $v, $subs, $per, $off, $r );
        } elseif ( 'post_taxonomies' === $type || 'terms' === $type ) {
            $note = self::terms( $row, $subs, $per, $off, $r );
        } elseif ( 'menus' === $type ) {
            $note = self::menus( $row, $v, $subs, $per, $off, $r );
        } elseif ( 'user_roles' === $type ) {
            foreach ( $subs as $role ) {
                if ( ! $r['role_exists']( $role ) ) {
                    $row['problems'][] = "The user role \"$role\" does not exist on this site.";
                }
            }
            $note = 'Users are not counted.';
        } elseif ( 'current_page' === $type ) {
            $note = 'Lists what the page it is shown on holds (an archive); on an ordinary page it lists nothing.';
        } elseif ( str_starts_with( $type, 'repeater_' ) && ! $r['acf']() ) {
            $row['problems'][] = 'Advanced Custom Fields is not active here: a repeater loop lists nothing.';
        } elseif ( str_starts_with( $type, 'repeater_' ) ) {
            $note = 'The repeater field is not read.';
        }
        if ( null !== $row['found'] && 0 === $row['found'] && [] === $row['problems'] ) {
            $row['problems'][] = 'Nothing on this site matches this loop: it will show "No Results Found."';
        }
        if ( null !== $note ) {
            $row['note'] = $note;
        }

        return $row;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $v @param list<string> $subs @param array<string,callable> $r */
    private static function posts( array &$row, array $v, array $subs, int $per, int $off, array $r ): ?string
    {
        $types = [];
        foreach ( $subs as $slug ) {
            if ( $r['post_type_exists']( $slug ) ) {
                $types[] = $slug;
            } else {
                $row['problems'][] = "The post type \"$slug\" does not exist on this site (get_site_structure lists the slugs); Divi ignores it, and with no other valid post type it lists EVERY post type, media files included.";
            }
        }
        if ( [] === $subs ) {
            return 'No subTypes: the loop reads every post type.';
        }
        $hasFilter = false;
        foreach ( self::FILTERS as $key ) {
            $hasFilter = $hasFilter || self::nonEmpty( $v[ $key ] ?? null );
        }
        $hasFilter = $hasFilter || ( is_string( $v['search'] ?? null ) && '' !== trim( $v['search'] ) ) || 'on' === ( $v['excludeCurrentPost'] ?? null );
        self::checkTerms( $row, $v, $r );
        if ( [] === $types ) {
            return null;
        }
        if ( in_array( 'attachment', $types, true ) ) {
            return 'Media files are not counted.';
        }
        if ( $hasFilter ) {
            return 'The loop has filters (terms, specific posts, meta, search or "exclude current post"); they are not counted.';
        }
        $order   = 'ascending' === ( $v['order'] ?? null ) ? 'ASC' : 'DESC';
        $orderBy = is_string( $v['orderBy'] ?? null ) ? ( self::ORDER_BY[ $v['orderBy'] ] ?? null ) : 'date';
        $found   = $r['posts']( [ 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => $per, 'offset' => $off, 'orderby' => $orderBy ?? 'date', 'order' => $order ] );
        if ( null === $found ) {
            return null;
        }
        $row['found']  = $found['found'];
        $row['listed'] = max( 0, min( $per, $found['found'] - $off ) );
        $sticky        = in_array( 'post', $types, true ) && 'on' !== ( $v['ignoreStickysPost'] ?? null ) && $r['has_stickies']();
        if ( null === $orderBy ) {
            return 'The order "' . (string) ( $v['orderBy'] ?? '' ) . '" is not reproduced, so the titles are not listed.';
        }
        if ( $sticky ) {
            return 'The site has sticky posts, which Divi can move to the front of page 1, so the titles are not listed.';
        }
        $row['first'] = array_slice( $found['titles'], 0, self::MAX_TITLES );

        return null;
    }

    /** Taxonomy and term ids named by the term filters must exist; a wrong one silently filters everything out. */
    private static function checkTerms( array &$row, array $v, array $r ): void
    {
        foreach ( [ 'includePostWithSpecificTerms', 'excludePostWithSpecificTerms' ] as $key ) {
            foreach ( is_array( $v[ $key ] ?? null ) ? $v[ $key ] : [] as $group ) {
                if ( ! is_array( $group ) || ! is_string( $group['categoryId'] ?? null ) ) {
                    continue;
                }
                $taxonomy = $group['categoryId'];
                if ( ! $r['taxonomy_exists']( $taxonomy ) ) {
                    $row['problems'][] = "The taxonomy \"$taxonomy\" in $key does not exist on this site: Divi ignores that filter and lists everything.";
                    continue;
                }
                foreach ( is_array( $group['selectedOptions'] ?? null ) ? $group['selectedOptions'] : [] as $option ) {
                    $id = is_array( $option ) && is_string( $option['value'] ?? null ) ? $option['value'] : '';
                    if ( ctype_digit( $id ) && ! $r['term_in']( (int) $id, $taxonomy ) ) {
                        $row['problems'][] = "The term id $id is not in the taxonomy \"$taxonomy\" ($key uses the term ID, not the slug): Divi ignores that filter and lists everything.";
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $row @param list<string> $subs @param array<string,callable> $r */
    private static function terms( array &$row, array $subs, int $per, int $off, array $r ): ?string
    {
        if ( [] === $subs ) {
            return 'No subTypes: name the taxonomy.';
        }
        $total = 0;
        foreach ( $subs as $taxonomy ) {
            if ( ! $r['taxonomy_exists']( $taxonomy ) ) {
                $row['problems'][] = "The taxonomy \"$taxonomy\" does not exist on this site (get_site_structure lists the slugs).";
                continue;
            }
            $total += (int) $r['terms_count']( $taxonomy );
        }
        if ( [] !== $row['problems'] ) {
            return null;
        }
        $row['found']  = $total;
        $row['listed'] = max( 0, min( $per, $total - $off ) );

        return 'Divi lists only terms that have items (empty terms are hidden).';
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $v @param list<string> $subs @param array<string,callable> $r */
    private static function menus( array &$row, array $v, array $subs, int $per, int $off, array $r ): ?string
    {
        if ( [] === $subs ) {
            $row['problems'][] = 'No menu is named in subTypes: a menu loop lists nothing (use the menu id from get_site_structure include=menus).';

            return null;
        }
        $titles = [];
        foreach ( $subs as $id ) {
            $menu = ctype_digit( $id ) ? $r['menu']( (int) $id ) : null;
            if ( null === $menu ) {
                $row['problems'][] = "No navigation menu has the id \"$id\" (get_site_structure include=menus lists them).";
                continue;
            }
            array_push( $titles, ...$menu );
        }
        if ( [] !== $row['problems'] ) {
            return null;
        }
        $orderBy = is_string( $v['orderBy'] ?? null ) ? $v['orderBy'] : 'menu_order';
        $row['found']  = count( $titles );
        $row['listed'] = max( 0, min( $per, count( $titles ) - $off ) );
        if ( 1 === count( $subs ) && 'menu_order' === $orderBy ) {
            $ordered      = 'ascending' === ( $v['order'] ?? null ) ? array_reverse( $titles ) : $titles;
            $row['first'] = array_slice( array_slice( $ordered, $off ), 0, self::MAX_TITLES );
        }

        return null;
    }

    /** @return list<string> the slug of every subTypes entry */
    private static function subValues( mixed $subs ): array
    {
        $out = [];
        foreach ( is_array( $subs ) ? $subs : [] as $item ) {
            if ( is_array( $item ) && is_string( $item['value'] ?? null ) && '' !== $item['value'] ) {
                $out[] = $item['value'];
            }
        }

        return $out;
    }

    private static function whole( mixed $value, int $default ): int
    {
        if ( is_int( $value ) && $value >= 0 ) {
            return $value;
        }

        return is_string( $value ) && ctype_digit( $value ) ? (int) $value : $default;
    }

    private static function nonEmpty( mixed $value ): bool
    {
        return is_array( $value ) && [] !== $value;
    }

    /**
     * The WordPress readers (each a plain function so tests can stand in for them).
     *
     * @return array<string,callable>
     */
    public static function readers(): array
    {
        return [
            'post_type_exists' => static fn ( string $slug ): bool => function_exists( 'post_type_exists' ) && post_type_exists( $slug ),
            'taxonomy_exists'  => static fn ( string $slug ): bool => function_exists( 'taxonomy_exists' ) && taxonomy_exists( $slug ),
            'term_in'          => static fn ( int $id, string $taxonomy ): bool => function_exists( 'term_exists' ) && is_array( term_exists( $id, $taxonomy ) ),
            'terms_count'      => static function ( string $taxonomy ): int {
                $n = function_exists( 'wp_count_terms' ) ? wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] ) : 0;

                return is_numeric( $n ) ? (int) $n : 0;
            },
            'role_exists'      => static fn ( string $role ): bool => function_exists( 'wp_roles' ) && null !== wp_roles()->get_role( $role ),
            'acf'              => static fn (): bool => function_exists( 'acf_get_field_groups' ),
            'has_stickies'     => static fn (): bool => function_exists( 'get_option' ) && [] !== (array) get_option( 'sticky_posts', [] ),
            'menu'             => static function ( int $id ): ?array {
                if ( ! function_exists( 'wp_get_nav_menu_object' ) || ! function_exists( 'wp_get_nav_menu_items' ) || ! wp_get_nav_menu_object( $id ) ) {
                    return null;
                }
                $items = wp_get_nav_menu_items( $id );

                return is_array( $items ) ? array_map( static fn ( $i ): string => mb_substr( (string) ( $i->title ?? '' ), 0, 100 ), $items ) : [];
            },
            'posts'            => static function ( array $args ): ?array {
                if ( ! class_exists( '\WP_Query' ) ) {
                    return null;
                }
                $query  = new \WP_Query( $args + [ 'ignore_sticky_posts' => true, 'no_found_rows' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ] );
                $titles = array_map( static fn ( $p ): string => mb_substr( (string) $p->post_title, 0, 100 ), $query->posts );

                return [ 'found' => (int) $query->found_posts, 'titles' => $titles ];
            },
        ];
    }
}
