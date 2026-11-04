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
     * @return array{header:?array{layout_id:int,title:string},menuModule:bool,applied:bool,menu:?array{name:string,items:int,panels:int,how:string},items:list<array{id:int,title:string,layout:?string,layoutName:?string}>}
     */
    public function status(): array
    {
        $header = $this->globalHeader();
        $state  = [ 'header' => $header, 'menuModule' => false, 'applied' => false, 'menu' => null, 'items' => [] ];
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
        }

        return $state;
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
            // Which layout each item with a panel gets.
            $byItem = [];
            if ( null !== $itemId ) {
                if ( ! $info['hasMega'] ) {
                    throw new \InvalidArgumentException( self::NO_MEGA_YET );
                }
                $panelItems = [];
                foreach ( $menu['tree'] as $item ) {
                    if ( [] !== $item['children'] ) {
                        $panelItems[ $item['id'] ] = $item;
                    }
                }
                if ( ! isset( $panelItems[ $itemId ] ) ) {
                    throw new \InvalidArgumentException( self::NO_SUCH_ITEM );
                }
                foreach ( HeaderEdit::panels( $stored ) as $id => $built ) {
                    $found = Layouts::find( $built );
                    if ( isset( $panelItems[ $id ] ) && null !== $found ) {
                        $byItem[ $id ] = $found;
                    }
                }
                $byItem[ $itemId ] = $layout;
            }
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
            $wrapper = ( new NavBuilder( new Blocks( $version ) ) )->buildMixed(
                $byItem,
                $layout,
                $menu['tree'],
                [ 'look' => $look, 'posts' => $posts, 'featureImage' => $image ? $this->featureImage() : '' ]
            );

            return [
                'content' => HeaderEdit::apply( $stored, $wrapper ),
                'extra'   => [ 'mega_menu' => [ 'layout' => $layout['id'], 'name' => $layout['name'], 'item' => $itemId, 'menu' => $menu['name'], 'menu_source' => $menu['how'], 'panels' => MenuTree::panelCount( $menu['tree'] ) ] ],
            ];
        }, $dry );
    }

    /** @return array<string,mixed> */
    public function remove( bool $dry = false ): array
    {
        $header = $this->requireHeader();

        return $this->writer->apply( $header['layout_id'], self::TOOL, static fn ( string $stored ): array => [ 'content' => HeaderEdit::remove( $stored ), 'extra' => [ 'mega_menu' => [ 'layout' => null ] ] ], $dry );
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
