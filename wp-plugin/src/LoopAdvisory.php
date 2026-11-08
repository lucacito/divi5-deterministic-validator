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
 * Tells the AI about two dynamic-content tokens that are valid but show the wrong thing next to a Loop Builder loop, so it can
 * correct itself. Advisory only: it never changes a verdict, blocks a save or alters content (same contract as ComponentAdvisory).
 * Live proof on Divi 5.15.1 (docs/loop-builder-evidence-5.15.1.md, make verify-loop-builder):
 *
 *  - `loop_outside_loop`: a `loop_*` token on a block that is not inside a loop that is switched on renders nothing.
 *  - `page_token_in_loop`: a `post_*` token (proven for `post_title`) inside a loop shows the PAGE's own value in every item,
 *    not the item's; the `loop_*` token of the same field shows the item's.
 *
 * "Inside a loop" is the block itself or any ancestor carrying `module.advanced.loop` with `enable` "on". A container whose loop
 * settings have no `enable` (what the builder writes on a grid group) is not a loop.
 */
final class LoopAdvisory
{
    public const MAX_ROWS = 20;

    public const MESSAGE = "A loop_ token only shows something inside a module that has a loop switched on (module.advanced.loop with \"enable\":\"on\", on the module itself or a container around it); anywhere else it renders nothing. A post_ token inside a loop shows the PAGE's own value in every item: use the loop_ token of the same field (loop_post_title, loop_post_link, loop_post_featured_image ...). Call get_style_guide with topic=loops for the list.";

    private const TOKEN = '/\$variable\((\{.*?\})\)\$/s';

    /**
     * @return ?array{loop_outside_loop: list<array{name:string,count:int}>, page_token_in_loop: list<array{name:string,count:int}>, message: string}
     */
    public static function advise( string $content ): ?array
    {
        if ( ! str_contains( $content, '$variable(' ) || CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }
        $outside = [];
        $page    = [];
        self::visit( $parsed->root(), false, $outside, $page );
        if ( [] === $outside && [] === $page ) {
            return null;
        }

        return [
            'loop_outside_loop'  => self::rows( $outside ),
            'page_token_in_loop' => self::rows( $page ),
            'message'            => self::MESSAGE,
        ];
    }

    /**
     * @param array<string,int> $outside
     * @param array<string,int> $page
     */
    private static function visit( Block $block, bool $inLoop, array &$outside, array &$page ): void
    {
        $inLoop = $inLoop || 'on' === $block->attr( 'module.advanced.loop.desktop.value.enable' );
        foreach ( self::names( $block->attrs() ) as $name ) {
            if ( str_starts_with( $name, 'loop_' ) && ! $inLoop ) {
                $outside[ $name ] = ( $outside[ $name ] ?? 0 ) + 1;
            } elseif ( str_starts_with( $name, 'post_' ) && $inLoop ) {
                $page[ $name ] = ( $page[ $name ] ?? 0 ) + 1;
            }
        }
        foreach ( $block->children() as $child ) {
            self::visit( $child, $inLoop, $outside, $page );
        }
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
