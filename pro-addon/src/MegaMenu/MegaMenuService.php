<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;
use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Applies a mega menu layout to the site's global header, or takes it away again. Everything that touches the stored
 * header goes through `LayoutWriter::apply` (the Theme Builder permission, the layout gate, the history snapshot, the
 * Divi cache clear and one undoable batch), exactly like the other Pro header tools.
 */
final class MegaMenuService
{
    private MenuSource $menus;

    private PostSource $posts;

    public const TOOL = 'apply_mega_menu';

    public const NO_HEADER = 'This site has no global header. Create one in Divi > Theme Builder (a header on the Default Website Template), then come back.';

    public const NO_MENU = 'The site has no menu. Create one in Appearance > Menus and add some items with sub-items.';

    public const NO_SUBITEMS = 'None of the menu\'s items has a sub-item, so there is nothing to show in a panel. Add sub-items under at least one item in Appearance > Menus.';

    public const NO_MEGA_YET = 'Put a layout on the whole menu first, then you can change one item.';

    public const NOTHING_TO_UPDATE = 'There is no mega menu on the header to update. Choose a layout first.';

    public const NO_SUCH_ITEM = 'That menu item has no panel (it has no sub-items), or it is no longer in the menu.';

    /**
     * @param ?\Closure $header returns the global header as [layout_id, title] or null (default: the default Theme Builder template's header)
     * @param ?\Closure $imageBase returns the URL of the built-in image pack folder
     */
    public function __construct(
        private LayoutWriter $writer,
        ?MenuSource $menus = null,
        ?PostSource $posts = null,
        private ?\Closure $header = null,
        private ?\Closure $imageBase = null,
        private ?\Closure $stored = null
    ) {
        $this->menus = $menus ?? new MenuSource();
        $this->posts = $posts ?? new PostSource();
    }

    /**
     * What the admin screen needs to know, without changing anything.
     *
     * `drift` is null when the mega menu matches the menu (or none is applied), else what differs: `{bar: {missing, extra, reordered}|null, items: list<{id, title, missing, extra, reordered}>, gone: list<int>}`
     * (`gone`: panels of items that no longer have sub-items or are no longer in the menu).
     *
     * @return array{header:?array{layout_id:int,title:string},menuModule:bool,applied:bool,menu:?array{name:string,items:int,panels:int,how:string},items:list<array{id:int,title:string,layout:?string,layoutName:?string}>,drift:?array<string,mixed>}
     */
    public function status(): array
    {
        $header = $this->globalHeader();
        $state  = [ 'header' => $header, 'menuModule' => false, 'applied' => false, 'menu' => null, 'items' => [], 'drift' => null ];
        if ( null === $header ) {
            return $state;
        }
        $info                = HeaderEdit::inspect( $this->storedContent( $header['layout_id'] ) );
        $state['menuModule'] = null !== $info['menu'];
        $state['applied']    = $info['hasMega'];
        $look                = null === $info['menu'] ? null : Look::fromMenuAttrs( $info['menu'] );
        $menu                = $this->menus->read( null === $look ? null : $look['menuId'] );
        if ( null !== $menu ) {
            $state['menu'] = [ 'name' => $menu['name'], 'items' => count( $menu['tree'] ), 'panels' => MenuTree::panelCount( $menu['tree'] ), 'how' => $menu['how'] ];
            // Every top-level item that has a panel, and (when a mega menu is applied) the layout that panel was built from.
            $built = $info['hasMega'] ? HeaderEdit::panels( $this->storedContent( $header['layout_id'] ) ) : [];
            foreach ( $menu['tree'] as $item ) {
                if ( [] !== $item['children'] ) {
                    $layout           = isset( $built[ $item['id'] ] ) ? Layouts::find( $built[ $item['id'] ] ) : null;
                    $state['items'][] = [ 'id' => (int) $item['id'], 'title' => (string) $item['title'], 'layout' => null === $layout ? null : (string) $layout['id'], 'layoutName' => null === $layout ? null : (string) $layout['name'] ];
                }
            }
            if ( $info['hasMega'] ) {
                $state['drift'] = $this->drift( $this->storedContent( $header['layout_id'] ), $info, $menu );
            }
        }

        return $state;
    }

    /**
     * Whether the panels in the header still match the menu: the same wrapper is built again from the menu as it is now (each
     * item keeps the layout its panel was built from) and compared with the stored one. Nothing is written.
     *
     * @param array{menu:?array<string,mixed>,hasMega:bool,builderVersion:?string} $info
     * @param array{name:string,tree:list<array<string,mixed>>,how:string} $menu
     * @return array<string,mixed>|null
     */
    private function drift( string $stored, array $info, array $menu ): ?array
    {
        $built = $this->builtLayouts( $stored, $menu );
        try {
            $fallback = $this->commonLayout( $stored );
            if ( null === $fallback || null === $info['menu'] || [] === $menu['tree'] || 0 === MenuTree::panelCount( $menu['tree'] ) ) {
                return null; // nothing sensible to compare with (the status already tells the owner why)
            }
            $wrapper = $this->wrapper( $info, Look::fromMenuAttrs( $info['menu'] ), $menu, $fallback, $built );
        } catch ( \InvalidArgumentException ) {
            return null;
        }
        $snapshots = [];
        foreach ( HeaderEdit::panels( $stored ) as $id => $layoutId ) {
            $found = Layouts::find( $layoutId );
            if ( null !== $found && Layouts::needsPosts( $found ) ) {
                $snapshots[] = $id;
            }
        }
        $diff = MenuDrift::compare( MenuDrift::signature( HeaderEdit::wrapper( $stored ) ), MenuDrift::signature( $wrapper ), $snapshots );
        if ( [] === $diff ) {
            return null;
        }
        $titles = [];
        foreach ( $menu['tree'] as $item ) {
            $titles[ (int) $item['id'] ] = (string) $item['title'];
        }
        $drift = [ 'bar' => $diff[ MenuDrift::TOP ] ?? null, 'items' => [], 'gone' => [] ];
        foreach ( $diff as $panel => $d ) {
            if ( MenuDrift::TOP === $panel ) {
                continue;
            }
            if ( isset( $titles[ $panel ] ) ) {
                $drift['items'][] = [ 'id' => $panel, 'title' => $titles[ $panel ] ] + $d;
            } else {
                $drift['gone'][] = $panel;
            }
        }

        return $drift;
    }

    /**
     * Puts a layout on the header: on every item that has a panel, or (`$itemId`) on that one top-level item only while the
     * panels of the other items stay exactly as they are (read back from the header's own panel labels).
     *
     * @return array<string,mixed> the LayoutWriter result (a dry run returns the preview)
     */
    public function apply( string $layoutId, bool $dry = false, ?int $itemId = null ): array
    {
        $layout = Layouts::find( $layoutId );
        if ( null === $layout ) {
            throw new \InvalidArgumentException( 'Unknown mega menu layout "' . $layoutId . '".' );
        }
        $header = $this->requireHeader();

        return $this->writer->apply( $header['layout_id'], self::TOOL, function ( string $stored ) use ( $layout, $itemId ): array {
            [ $info, $look, $menu ] = $this->read( $stored );
            // Which layout each item with a panel gets.
            $byItem = [];
            if ( null !== $itemId ) {
                if ( ! $info['hasMega'] ) {
                    throw new \InvalidArgumentException( self::NO_MEGA_YET );
                }
                $byItem = $this->builtLayouts( $stored, $menu );
                if ( ! $this->hasPanel( $menu, $itemId ) ) {
                    throw new \InvalidArgumentException( self::NO_SUCH_ITEM );
                }
                $byItem[ $itemId ] = $layout;
            }

            return [
                'content' => HeaderEdit::apply( $stored, $this->wrapper( $info, $look, $menu, $layout, $byItem ) ),
                'extra'   => [ 'mega_menu' => [ 'layout' => $layout['id'], 'name' => $layout['name'], 'item' => $itemId, 'menu' => $menu['name'], 'menu_source' => $menu['how'], 'panels' => MenuTree::panelCount( $menu['tree'] ) ] ],
            ];
        }, $dry );
    }

    /**
     * Builds the mega menu again from the menu as it is now, keeping the layout each item's panel has (an item that had no
     * panel before gets the layout most panels have). The answer to "your menu changed".
     *
     * @return array<string,mixed> the LayoutWriter result (a dry run returns the preview)
     */
    public function refresh( bool $dry = false ): array
    {
        $header = $this->requireHeader();

        return $this->writer->apply( $header['layout_id'], self::TOOL, function ( string $stored ): array {
            [ $info, $look, $menu ] = $this->read( $stored );
            $layout                 = $this->commonLayout( $stored );
            if ( ! $info['hasMega'] || null === $layout ) {
                throw new \InvalidArgumentException( self::NOTHING_TO_UPDATE );
            }

            return [
                'content' => HeaderEdit::apply( $stored, $this->wrapper( $info, $look, $menu, $layout, $this->builtLayouts( $stored, $menu ) ) ),
                'extra'   => [ 'mega_menu' => [ 'layout' => $layout['id'], 'name' => $layout['name'], 'item' => null, 'refresh' => true, 'menu' => $menu['name'], 'menu_source' => $menu['how'], 'panels' => MenuTree::panelCount( $menu['tree'] ) ] ],
            ];
        }, $dry );
    }

    /** @return array<string,mixed> */
    public function remove( bool $dry = false ): array
    {
        $header = $this->requireHeader();

        return $this->writer->apply( $header['layout_id'], self::TOOL, static fn ( string $stored ): array => [ 'content' => HeaderEdit::remove( $stored ), 'extra' => [ 'mega_menu' => [ 'layout' => null ] ] ], $dry );
    }

    // ---- building --------------------------------------------------------------------------------------------------

    /**
     * The header's Menu module, the look taken from it and the menu it shows; refuses when there is nothing to build from.
     *
     * @return array{0:array{menu:?array<string,mixed>,hasMega:bool,builderVersion:?string},1:array<string,mixed>,2:array{name:string,tree:list<array<string,mixed>>,how:string}}
     */
    private function read( string $stored ): array
    {
        $info = HeaderEdit::inspect( $stored );
        if ( null === $info['menu'] ) {
            throw new \InvalidArgumentException( HeaderEdit::NO_MENU );
        }
        $look = Look::fromMenuAttrs( $info['menu'] );
        $menu = $this->menus->read( $look['menuId'] );
        if ( null === $menu || [] === $menu['tree'] ) {
            throw new \InvalidArgumentException( self::NO_MENU );
        }
        if ( 0 === MenuTree::panelCount( $menu['tree'] ) ) {
            throw new \InvalidArgumentException( self::NO_SUBITEMS );
        }

        return [ $info, $look, $menu ];
    }

    /**
     * The wrapper block markup for the menu. `$byItem` maps a top-level item id to its layout; an item not in it takes
     * `$layout`. The layouts that show posts get the newest ones.
     *
     * @param array<string,mixed> $info
     * @param array<string,mixed> $look
     * @param array<string,mixed> $menu
     * @param array<string,mixed> $layout
     * @param array<int,array<string,mixed>> $byItem
     */
    private function wrapper( array $info, array $look, array $menu, array $layout, array $byItem ): string
    {
        $posts = [];
        $image = false;
        foreach ( $menu['tree'] as $item ) {
            if ( [] === $item['children'] ) {
                continue;
            }
            $mine = $byItem[ $item['id'] ] ?? $layout;
            if ( Layouts::needsPosts( $mine ) ) {
                $posts[ $item['id'] ] = $this->posts->latest( $item, 4 );
            }
            $image = $image || Layouts::needsFeatureImage( $mine );
        }
        $version = is_string( $info['builderVersion'] ) && version_compare( $info['builderVersion'], '5.8.0', '>=' ) ? $info['builderVersion'] : '5.8.0';

        return ( new NavBuilder( new Blocks( $version ) ) )->buildMixed(
            $byItem,
            $layout,
            $menu['tree'],
            [ 'look' => $look, 'posts' => $posts, 'featureImage' => $image ? $this->featureImage() : '' ]
        );
    }

    /**
     * The layout each top-level item that still has sub-items got, read back from the stored panel labels.
     *
     * @param array<string,mixed> $menu
     * @return array<int,array<string,mixed>> item id => one entry of Layouts::all()
     */
    private function builtLayouts( string $stored, array $menu ): array
    {
        $out = [];
        foreach ( HeaderEdit::panels( $stored ) as $id => $built ) {
            $found = Layouts::find( $built );
            if ( null !== $found && $this->hasPanel( $menu, $id ) ) {
                $out[ $id ] = $found;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $menu */
    private function hasPanel( array $menu, int $itemId ): bool
    {
        foreach ( $menu['tree'] as $item ) {
            if ( (int) $item['id'] === $itemId && [] !== $item['children'] ) {
                return true;
            }
        }

        return false;
    }

    /** The layout most of the stored panels were built from (the first one wins a tie), or null when none is recognised. @return array<string,mixed>|null */
    private function commonLayout( string $stored ): ?array
    {
        $count = [];
        foreach ( HeaderEdit::panels( $stored ) as $built ) {
            if ( null !== Layouts::find( $built ) ) {
                $count[ $built ] = ( $count[ $built ] ?? 0 ) + 1;
            }
        }
        if ( [] === $count ) {
            return null;
        }
        arsort( $count ); // stable for equal counts since PHP 8.0: the first one seen stays first

        return Layouts::find( (string) array_key_first( $count ) );
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    /** @return array{layout_id:int,title:string}|null */
    public function globalHeader(): ?array
    {
        if ( null !== $this->header ) {
            return ( $this->header )();
        }
        foreach ( ThemeLayouts::liveTemplates() as $template ) {
            if ( $template['default'] && $template['enabled'] && null !== $template['header'] && $template['header']['enabled'] ) {
                return [ 'layout_id' => (int) $template['header']['layout_id'], 'title' => (string) $template['header']['title'] ];
            }
        }

        return null;
    }

    /** @return array{layout_id:int,title:string} */
    private function requireHeader(): array
    {
        LayoutTarget::assertMay();
        $header = $this->globalHeader();
        if ( null === $header ) {
            throw new \InvalidArgumentException( self::NO_HEADER );
        }

        return $header;
    }

    private function storedContent( int $layoutId ): string
    {
        if ( null !== $this->stored ) {
            return (string) ( $this->stored )( $layoutId );
        }
        $post = get_post( $layoutId );

        return $post ? (string) $post->post_content : '';
    }

    private function featureImage(): string
    {
        $base = null !== $this->imageBase ? ( $this->imageBase )() : ( defined( 'AI_EDITOR_DIVI5_FILE' ) ? plugins_url( 'assets/images/', AI_EDITOR_DIVI5_FILE ) : '' );

        // The free plugin's built-in pack picture "card-blue-1" (manifest.json: file card-blue-1.svg, 4:3).
        return '' === $base ? '' : rtrim( $base, '/' ) . '/card-blue-1.svg';
    }
}
