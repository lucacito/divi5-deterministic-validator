<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Edits the stored content of a Theme Builder header to carry (or drop) the mega menu. Pure string work: it changes
 * nothing but the first Menu module's `disabledOn` and the one wrapper group labelled `JHMG Mega Menu`; every other byte
 * of the header is returned as it was.
 *
 *   apply():  removes an earlier wrapper, hides the Menu module on desktop only, puts the new wrapper right after it
 *   remove(): removes the wrapper and shows the Menu module on desktop again
 */
final class HeaderEdit
{
    public const NO_MENU = 'The header has no Menu module to put the mega menu next to. Add Divi\'s Menu module to the header first.';

    /** What apply() writes on the Menu module, and what remove() recognises as its own. */
    private const MENU_HIDDEN_ON_DESKTOP = [ 'desktop' => [ 'value' => 'on' ], 'tablet' => [ 'value' => 'off' ], 'phone' => [ 'value' => 'off' ] ];

    /** A block comment that carries attributes: opener (1), name (2), attribute JSON (3), closer (4). The JSON never holds `-->` (Blocks::encode escapes it). */
    private const BLOCK = '/(<!--\s+wp:(divi\/[a-z0-9-]+)\s+)(\{.*?\})(\s*\/?-->)/s';

    private const ANY_COMMENT = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+\{.*?\})?\s*(\/?)-->/s';

    /**
     * What the header holds now.
     *
     * @return array{menu:?array<string,mixed>,hasMega:bool,builderVersion:?string}
     */
    public static function inspect( string $content ): array
    {
        $menu = self::findMenu( $content );
        $attrs = null === $menu ? null : $menu['attrs'];

        return [
            'menu'           => $attrs,
            'hasMega'        => null !== self::findWrapper( $content ),
            'builderVersion' => is_array( $attrs ) && is_string( $attrs['builderVersion'] ?? null ) ? $attrs['builderVersion'] : null,
        ];
    }

    /**
     * The layout each panel was built from, read back from the panels' admin labels (see Blocks::panelLabel).
     *
     * @return array<int,string> top-level menu item id => layout id
     */
    public static function panels( string $content ): array
    {
        $out = [];
        if ( false === preg_match_all( self::BLOCK, $content, $all, PREG_SET_ORDER ) ) {
            return $out;
        }
        foreach ( $all as $m ) {
            if ( 'divi/dropdown' !== $m[2] ) {
                continue;
            }
            $attrs = json_decode( $m[3], true );
            $label = is_array( $attrs ) ? ( $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? null ) : null;
            if ( is_string( $label ) && 1 === preg_match( '/^JHMG panel (\d+) ([a-z0-9-]+)(?: [0-9a-f]{10})?$/', $label, $l ) ) {
                $out[ (int) $l[1] ] = $l[2];
            }
        }

        return $out;
    }

    /**
     * The markup of each panel (the Dropdown block with the admin label `JHMG panel <item id> <layout>`, opening comment to closing
     * comment) inside the mega menu wrapper.
     *
     * @return array<int,string> top-level menu item id => the panel's block markup
     */
    public static function panelBlocks( string $content ): array
    {
        $wrapper = self::wrapper( $content );
        $out     = [];
        if ( '' === $wrapper || false === preg_match_all( self::ANY_COMMENT, $wrapper, $comments, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return $out;
        }
        $open = null; // [item id, start offset, depth when it opened]
        $depth = 0;
        foreach ( $comments as $c ) {
            $closer = '/' === $c[1][0];
            $self   = '/' === $c[3][0];
            if ( null === $open && ! $closer && 'divi/dropdown' === $c[2][0] ) {
                $attrs = [];
                if ( 1 === preg_match( '/\{.*\}/s', $c[0][0], $j ) ) {
                    $attrs = json_decode( $j[0], true );
                }
                $label = is_array( $attrs ) ? ( $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? null ) : null;
                if ( is_string( $label ) && 1 === preg_match( '/^JHMG panel (\d+) /', $label, $l ) && ! $self ) {
                    $open = [ (int) $l[1], $c[0][1], $depth ];
                }
            }
            if ( $closer ) {
                --$depth;
            } elseif ( ! $self ) {
                ++$depth;
            }
            if ( null !== $open && $closer && $depth === $open[2] ) {
                $out[ $open[0] ] = substr( $wrapper, $open[1], $c[0][1] + strlen( $c[0][0] ) - $open[1] );
                $open            = null;
            }
        }

        return $out;
    }

    /** The mega menu wrapper block as stored (opening comment to closing comment), or '' when the header has none. */
    public static function wrapper( string $content ): string
    {
        $found = self::findWrapper( $content );

        return null === $found ? '' : substr( $content, $found['start'], $found['end'] - $found['start'] );
    }

    public static function apply( string $content, string $wrapper ): string
    {
        $content = self::stripWrapper( $content );
        $menu    = self::findMenu( $content );
        if ( null === $menu ) {
            throw new \InvalidArgumentException( self::NO_MENU );
        }
        // Edit through objects, not arrays: an empty `{}` in the Menu module's settings must stay `{}`.
        $obj = json_decode( $menu['json'] );
        if ( ! $obj instanceof \stdClass ) {
            throw new \InvalidArgumentException( self::NO_MENU );
        }
        if ( ! isset( $obj->module ) || ! $obj->module instanceof \stdClass ) {
            $obj->module = new \stdClass();
        }
        if ( ! isset( $obj->module->decoration ) || ! $obj->module->decoration instanceof \stdClass ) {
            $obj->module->decoration = new \stdClass();
        }
        $obj->module->decoration->disabledOn = json_decode( (string) json_encode( self::MENU_HIDDEN_ON_DESKTOP ) );
        $rewritten = $menu['open'] . Blocks::encode( $obj ) . $menu['close'];

        return substr( $content, 0, $menu['start'] ) . $rewritten . $wrapper . substr( $content, $menu['end'] );
    }

    /**
     * Points the header's Menu module at another menu (its `Menu` option), changing nothing else in the header.
     *
     * @throws \InvalidArgumentException when the header has no Menu module
     */
    public static function setMenu( string $content, int $menuId ): string
    {
        $menu = self::findMenu( $content );
        $obj  = null === $menu ? null : json_decode( $menu['json'] );
        if ( null === $menu || ! $obj instanceof \stdClass ) {
            throw new \InvalidArgumentException( self::NO_MENU );
        }
        $cur = $obj;
        foreach ( [ 'menu', 'advanced', 'menuId', 'desktop' ] as $key ) {
            if ( ! isset( $cur->$key ) || ! $cur->$key instanceof \stdClass ) {
                $cur->$key = new \stdClass();
            }
            $cur = $cur->$key;
        }
        $cur->value = (string) $menuId;
        $rewritten  = $menu['open'] . Blocks::encode( $obj ) . $menu['close'];

        return substr( $content, 0, $menu['start'] ) . $rewritten . substr( $content, $menu['end'] );
    }

    public static function remove( string $content ): string
    {
        $content = self::stripWrapper( $content );
        $menu    = self::findMenu( $content );
        if ( null === $menu || ( $menu['attrs']['module']['decoration']['disabledOn'] ?? null ) !== self::MENU_HIDDEN_ON_DESKTOP ) {
            return $content;
        }
        $obj = json_decode( $menu['json'] );
        if ( ! $obj instanceof \stdClass ) {
            return $content;
        }
        unset( $obj->module->decoration->disabledOn );
        if ( [] === (array) $obj->module->decoration ) {
            unset( $obj->module->decoration );
        }
        if ( [] === (array) $obj->module ) {
            unset( $obj->module );
        }
        $rewritten = $menu['open'] . Blocks::encode( $obj ) . $menu['close'];

        return substr( $content, 0, $menu['start'] ) . $rewritten . substr( $content, $menu['end'] );
    }

    // ---- scanning --------------------------------------------------------------------------------------------------

    /** @return array{start:int,end:int,open:string,close:string,json:string,attrs:array<string,mixed>}|null the first self-closing Menu module */
    private static function findMenu( string $content ): ?array
    {
        if ( false === preg_match_all( self::BLOCK, $content, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return null;
        }
        foreach ( $all as $m ) {
            if ( 'divi/menu' !== $m[2][0] || '/' !== substr( ltrim( $m[4][0] ), 0, 1 ) ) {
                continue;
            }
            $attrs = json_decode( $m[3][0], true );
            if ( ! is_array( $attrs ) ) {
                continue;
            }

            return [ 'start' => $m[0][1], 'end' => $m[0][1] + strlen( $m[0][0] ), 'open' => $m[1][0], 'close' => $m[4][0], 'json' => $m[3][0], 'attrs' => $attrs ];
        }

        return null;
    }

    /** @return array{start:int,end:int}|null the byte range of the labelled wrapper group, closing comment included */
    private static function findWrapper( string $content ): ?array
    {
        if ( false === preg_match_all( self::BLOCK, $content, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return null;
        }
        foreach ( $all as $m ) {
            if ( 'divi/group' !== $m[2][0] || '/' === substr( ltrim( $m[4][0] ), 0, 1 ) ) {
                continue;
            }
            $attrs = json_decode( $m[3][0], true );
            if ( ! is_array( $attrs ) || Blocks::ADMIN_LABEL !== ( $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? null ) ) {
                continue;
            }
            $start = $m[0][1];
            $depth = 0;
            if ( false === preg_match_all( self::ANY_COMMENT, $content, $comments, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start ) ) {
                return null;
            }
            foreach ( $comments as $c ) {
                if ( '/' === $c[1][0] ) {
                    --$depth;
                } elseif ( '/' !== $c[3][0] ) {
                    ++$depth;
                }
                if ( 0 === $depth ) {
                    return [ 'start' => $start, 'end' => $c[0][1] + strlen( $c[0][0] ) ];
                }
            }

            return null;
        }

        return null;
    }

    private static function stripWrapper( string $content ): string
    {
        $found = self::findWrapper( $content );

        return null === $found ? $content : substr( $content, 0, $found['start'] ) . substr( $content, $found['end'] );
    }
}
