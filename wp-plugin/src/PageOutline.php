<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A cheap way to read a page. The full layout of a real page is 12,000 to 16,000 tokens; the AI usually needs to know what is on
 * the page and to read one part of it. `view: "outline"` lists every block (a path such as `0.1.2`, its name, its size in bytes
 * and, for a module, the start of its visible text); `view: "block"` with a `path` returns exactly that block's saved markup (the
 * same bytes as in the full layout, so it can be used as an `edit_page_content` find string). Counting and slicing only: nothing
 * is interpreted, nothing is written, the same page always gives the same answer.
 *
 * A path is the index of the block among its siblings at each level, from the top: `0` is the first top-level block, `0.0` its first
 * child, `0.0.2` that child's third child.
 */
final class PageOutline
{
    public const VIEWS      = [ 'full', 'outline', 'block' ];
    public const MAX_BLOCKS = 400;
    public const MAX_TEXT   = 90;

    private const DELIMITER = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*|[a-z][a-z0-9_-]*)(?:\s+(\{.*?\}))?\s*(\/)?-->/s';

    private const STRUCTURE = [ 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/row-inner', 'divi/column-inner', 'divi/group', 'divi/global-layout', 'divi/global-slot' ];

    /**
     * The `view` and `path` arguments of a read. Strict: a value of the wrong type or an unknown view is an error, never read as the default.
     *
     * @return array{error: ?string, view: string, path: ?string}
     */
    public static function parseView( mixed $view, mixed $path ): array
    {
        if ( null === $view || '' === $view ) {
            $view = 'full';
        }
        if ( ! is_string( $view ) || ! in_array( $view, self::VIEWS, true ) ) {
            return [ 'error' => '"view" must be one of: ' . implode( ', ', self::VIEWS ) . '.', 'view' => 'full', 'path' => null ];
        }
        if ( null !== $path && ! is_string( $path ) ) {
            return [ 'error' => '"path" must be a string such as "0.1.2".', 'view' => $view, 'path' => null ];
        }
        $path = ( null === $path || '' === $path ) ? null : $path;
        if ( 'block' === $view && ( null === $path || 1 !== preg_match( '/^\d+(?:\.\d+)*$/', $path ) ) ) {
            return [ 'error' => 'view "block" needs a "path" such as "0.1.2" (take it from view "outline").', 'view' => $view, 'path' => null ];
        }
        if ( 'block' !== $view && null !== $path ) {
            return [ 'error' => '"path" is only used with view "block".', 'view' => $view, 'path' => null ];
        }

        return [ 'error' => null, 'view' => $view, 'path' => $path ];
    }

    /**
     * Every block with its place in the content. Null when the block comments are not balanced (it cannot be read reliably).
     *
     * @return ?list<array{path:string,name:string,start:int,end:int,attrs:array<mixed>}>
     */
    public static function scan( string $content ): ?array
    {
        if ( ! preg_match_all( self::DELIMITER, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return [];
        }
        $nodes = [];
        $stack = [];   // indexes into $nodes of the open blocks
        $count = []; // path prefix => index of the last child seen
        foreach ( $m as $d ) {
            $closer = '' !== $d[1][0];
            $name   = self::fullName( $d[2][0] );
            $void   = isset( $d[4] ) && '' !== $d[4][0];
            $end    = $d[0][1] + strlen( $d[0][0] );
            if ( $closer ) {
                if ( [] === $stack || $nodes[ end( $stack ) ]['name'] !== $name ) {
                    return null;
                }
                $nodes[ array_pop( $stack ) ]['end'] = $end;
                continue;
            }
            $parent         = [] === $stack ? '' : $nodes[ end( $stack ) ]['path'];
            $index          = $count[ $parent ] = ( $count[ $parent ] ?? -1 ) + 1;
            $path           = '' === $parent ? (string) $index : $parent . '.' . $index;
            $attrs          = isset( $d[3] ) && '' !== $d[3][0] ? json_decode( $d[3][0], true ) : [];
            $nodes[]        = [ 'path' => $path, 'name' => $name, 'start' => $d[0][1], 'end' => $end, 'attrs' => is_array( $attrs ) ? $attrs : [] ];
            $count[ $path ] = -1;
            if ( ! $void ) {
                $stack[] = count( $nodes ) - 1;
            }
        }

        return [] === $stack ? $nodes : null;
    }

    /**
     * @return ?array{blocks: list<array<string,mixed>>, total: int, truncated: bool}
     */
    public static function outline( string $content ): ?array
    {
        $nodes = self::scan( $content );
        if ( null === $nodes ) {
            return null;
        }
        $rows = [];
        foreach ( array_slice( $nodes, 0, self::MAX_BLOCKS ) as $n ) {
            $row = [ 'path' => $n['path'], 'name' => $n['name'], 'bytes' => $n['end'] - $n['start'] ];
            if ( ! in_array( $n['name'], self::STRUCTURE, true ) ) {
                $text = self::firstText( $n['attrs'], false );
                if ( '' !== $text ) {
                    $row['text'] = $text;
                }
            }
            $rows[] = $row;
        }

        return [ 'blocks' => $rows, 'total' => count( $nodes ), 'truncated' => count( $nodes ) > self::MAX_BLOCKS ];
    }

    /** One block's exact saved markup, with its name; null when there is no block at that path (or the content cannot be read). @return ?array{name:string,content:string} */
    public static function block( string $content, string $path ): ?array
    {
        $nodes = self::scan( $content );
        foreach ( $nodes ?? [] as $n ) {
            if ( $n['path'] === $path ) {
                return [ 'name' => $n['name'], 'content' => substr( $content, $n['start'], $n['end'] - $n['start'] ) ];
            }
        }

        return null;
    }

    /**
     * The answer for a non-full view, shared by the REST and MCP handlers.
     *
     * @param array<string,mixed>                          $meta post_id, post_type, post_title, post_status
     * @param array{error: ?string, view: string, path: ?string} $view from parseView(), with no error
     * @return array{status:int, body:array<string,mixed>}
     */
    public static function respond( array $meta, string $content, array $view ): array
    {
        if ( 'block' === $view['view'] ) {
            $b = self::block( $content, (string) $view['path'] );
            if ( null === $b ) {
                return [ 'status' => 404, 'body' => [ 'error' => 'No block at path "' . $view['path'] . '". Read view "outline" for the paths that exist.' ] ];
            }

            return [ 'status' => 200, 'body' => $meta + [ 'view' => 'block', 'path' => $view['path'], 'name' => $b['name'], 'block_content' => $b['content'] ] ];
        }
        $o = self::outline( $content );
        if ( null === $o ) {
            return [ 'status' => 422, 'body' => [ 'error' => "This page's saved blocks are not balanced, so it cannot be outlined. Read the full layout (view omitted)." ] ];
        }

        return [ 'status' => 200, 'body' => $meta + [
            'view'         => 'outline',
            'blocks'       => $o['blocks'],
            'total_blocks' => $o['total'],
            'truncated'    => $o['truncated'],
            'bytes_full'   => strlen( $content ),
            'note'         => 'Each path is the block\'s index among its siblings at every level. Read one block\'s exact markup with view "block" and its path; read the whole page by leaving view out.',
        ] ];
    }

    private static function fullName( string $name ): string
    {
        return str_contains( $name, '/' ) ? $name : 'core/' . $name;
    }

    /** The first visible text under an `innerContent` key, tags stripped and cut. @param array<mixed> $node */
    private static function firstText( array $node, bool $inContent ): string
    {
        foreach ( $node as $key => $value ) {
            $content = $inContent || 'innerContent' === $key;
            if ( is_array( $value ) ) {
                $t = self::firstText( $value, $content );
                if ( '' !== $t ) {
                    return $t;
                }
            } elseif ( $content && is_string( $value ) ) {
                $plain = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
                if ( '' !== $plain ) {
                    return mb_strlen( $plain ) > self::MAX_TEXT ? mb_substr( $plain, 0, self::MAX_TEXT ) . '…' : $plain;
                }
            }
        }

        return '';
    }
}
