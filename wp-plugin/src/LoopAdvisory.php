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
 * Tells the AI about loop mistakes that are valid but show the wrong thing, so it can correct itself. Advisory only: it never
 * changes a verdict, blocks a save or alters content (same contract as ComponentAdvisory). Live proof on Divi 5.15.1
 * (docs/loop-builder-evidence-5.15.1.md, make verify-loop-builder):
 *
 *  - `loop_outside_loop`: a `loop_*` token on a block that is not inside a loop that is switched on renders nothing.
 *  - `page_token_in_loop`: a `post_*` token (proven for `post_title`) inside a loop shows the PAGE's own value in every item,
 *    not the item's; the `loop_*` token of the same field shows the item's.
 *  - `token_for_other_loop`: a `loop_*` token of another kind than the innermost loop lists (a post token in a menu or terms
 *    loop, proven) renders empty.
 *  - `duplicate_loop_id`: two loops with one `loopId` page together (?loopId=2 moves both) and the builder repairs duplicates on save.
 *  - `post_nav_target_missing`: a Post Navigation module aimed at a loop id nothing on the page has prints no links.
 *  - `menu_loop_backwards`: a menu loop ordered by menu order with "ascending" shows the menu backwards (Divi 5.15.1).
 *
 * "Inside a loop" is the block itself or any ancestor carrying `module.advanced.loop` with `enable` "on". A container whose loop
 * settings have no `enable` (what the builder writes on a grid group) is not a loop.
 */
final class LoopAdvisory
{
    public const MAX_ROWS = 20;

    public const MESSAGE = "A loop_ token only shows something inside a module that has a loop switched on (module.advanced.loop with \"enable\":\"on\", on the module itself or a container around it); anywhere else it renders nothing. A post_ token inside a loop shows the PAGE's own value in every item: use the loop_ token of the same field (loop_post_title, loop_post_link, loop_post_featured_image ...). A loop_ token must also match the innermost loop: post tokens in post_types and current_page loops, loop_term_ in post_taxonomies, loop_user_ in user_roles, loop_menu_ in menus. Every loop needs its own loopId, and a Post Navigation module's targetLoop must be one of them. A menu loop shows the menu in its own order with order \"descending\". Call get_style_guide with topic=loops for the list.";

    private const TOKEN = '/\$variable\((\{.*?\})\)\$/s';

    /** The innermost loop's query type each token family belongs to. */
    private const FAMILIES = [
        'loop_menu_' => [ 'menus' ],
        'loop_term_' => [ 'post_taxonomies', 'terms' ],
        'loop_user_' => [ 'user_roles' ],
    ];

    /**
     * @return ?array{loop_outside_loop: list<array{name:string,count:int}>, page_token_in_loop: list<array{name:string,count:int}>, token_for_other_loop: list<array{name:string,count:int}>, duplicate_loop_id: list<array{name:string,count:int}>, post_nav_target_missing: list<array{name:string,count:int}>, menu_loop_backwards: int, message: string}
     */
    public static function advise( string $content ): ?array
    {
        if ( CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) || ! ( str_contains( $content, '$variable(' ) || str_contains( $content, 'targetLoop' ) || str_contains( $content, 'loopId' ) ) ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }
        $found = [ 'outside' => [], 'page' => [], 'other' => [], 'ids' => [], 'targets' => [], 'backwards' => 0 ];
        self::visit( $parsed->root(), null, $found );

        $duplicates = array_filter( $found['ids'], static fn( int $n ): bool => $n > 1 );
        $missing    = [];
        foreach ( $found['targets'] as $target => $count ) {
            if ( ! isset( $found['ids'][ $target ] ) ) {
                $missing[ $target ] = $count;
            }
        }
        if ( [] === $found['outside'] && [] === $found['page'] && [] === $found['other'] && [] === $duplicates && [] === $missing && 0 === $found['backwards'] ) {
            return null;
        }

        return [
            'loop_outside_loop'       => self::rows( $found['outside'] ),
            'page_token_in_loop'      => self::rows( $found['page'] ),
            'token_for_other_loop'    => self::rows( $found['other'] ),
            'duplicate_loop_id'       => self::rows( $duplicates ),
            'post_nav_target_missing' => self::rows( $missing ),
            'menu_loop_backwards'     => $found['backwards'],
            'message'                 => self::MESSAGE,
        ];
    }

    /**
     * @param ?string                $queryType The innermost enabled loop's query type around this block, null outside any loop.
     * @param array<string,mixed>    $found
     */
    private static function visit( Block $block, ?string $queryType, array &$found ): void
    {
        $value = $block->attr( 'module.advanced.loop.desktop.value' );
        if ( is_array( $value ) ) {
            $id = $value['loopId'] ?? null;
            if ( is_string( $id ) && '' !== $id && ( 'on' === ( $value['enable'] ?? null ) ) ) {
                $found['ids'][ $id ] = ( $found['ids'][ $id ] ?? 0 ) + 1;
            }
            if ( 'on' === ( $value['enable'] ?? null ) ) {
                $type = $value['queryType'] ?? 'post_types';
                $queryType = is_string( $type ) ? $type : 'post_types';
                if ( 'menus' === $queryType && 'ascending' === ( $value['order'] ?? null ) && in_array( $value['orderBy'] ?? 'menu_order', [ 'menu_order' ], true ) ) {
                    ++$found['backwards'];
                }
            }
        }
        $target = $block->attr( 'module.advanced.targetLoop.desktop.value' );
        if ( 'divi/post-nav' === $block->name() && is_string( $target ) && '' !== trim( $target ) && 'main_query' !== trim( $target ) ) {
            $found['targets'][ trim( $target ) ] = ( $found['targets'][ trim( $target ) ] ?? 0 ) + 1;
        }
        foreach ( self::names( $block->attrs() ) as $name ) {
            if ( str_starts_with( $name, 'loop_' ) ) {
                if ( null === $queryType ) {
                    $found['outside'][ $name ] = ( $found['outside'][ $name ] ?? 0 ) + 1;
                } elseif ( ! self::fits( $name, $queryType ) ) {
                    $found['other'][ $name ] = ( $found['other'][ $name ] ?? 0 ) + 1;
                }
            } elseif ( str_starts_with( $name, 'post_' ) && null !== $queryType ) {
                $found['page'][ $name ] = ( $found['page'][ $name ] ?? 0 ) + 1;
            }
        }
        foreach ( $block->children() as $child ) {
            self::visit( $child, $queryType, $found );
        }
    }

    /** Does a loop_ token belong to a loop of this query type? Repeater loops are not judged. */
    private static function fits( string $name, string $queryType ): bool
    {
        if ( str_starts_with( $queryType, 'repeater_' ) ) {
            return true;
        }
        foreach ( self::FAMILIES as $prefix => $types ) {
            if ( str_starts_with( $name, $prefix ) ) {
                return in_array( $queryType, $types, true );
            }
        }

        return in_array( $queryType, [ 'post_types', 'current_page' ], true );
    }

    /** The `name` of every well-formed dynamic-content token in any string value, repeats included. @param array<mixed> $node @return list<string> */
    private static function names( array $node ): array
    {
        $out = [];
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                array_push( $out, ...self::names( $value ) );
            } elseif ( is_string( $value ) && preg_match_all( self::TOKEN, $value, $m ) ) {
                foreach ( $m[1] as $json ) {
                    $token = json_decode( $json, true );
                    $name  = is_array( $token ) && is_array( $token['value'] ?? null ) ? ( $token['value']['name'] ?? null ) : null;
                    if ( is_string( $name ) && '' !== $name ) {
                        $out[] = $name;
                    }
                }
            }
        }

        return $out;
    }

    /** @param array<string,int> $counts @return list<array{name:string,count:int}> */
    private static function rows( array $counts ): array
    {
        $rows = [];
        foreach ( array_slice( $counts, 0, self::MAX_ROWS, true ) as $name => $count ) {
            $rows[] = [ 'name' => substr( (string) $name, 0, 100 ), 'count' => $count ];
        }

        return $rows;
    }
}
