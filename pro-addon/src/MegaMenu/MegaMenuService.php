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

    private MegaMenuContent $content;


    public const TOOL = 'apply_mega_menu';

    public const NO_HEADER = 'This site has no global header. Create one in Divi > Theme Builder (a header on the Default Website Template), then come back.';

    public const NO_MENU = 'The site has no menu. Create one in Appearance > Menus and add some items with sub-items.';

    public const NO_SUBITEMS = 'None of the menu\'s items has a sub-item, so there is nothing to show in a panel. Add sub-items under at least one item in Appearance > Menus.';

    public const NO_MEGA_YET = 'Put a layout on the whole menu first, then you can change one item.';

    public const NOTHING_TO_UPDATE = 'There is no mega menu on the header to update. Choose a layout first.';

    public const NO_SUCH_MENU = 'That menu does not exist (any more). Choose one from the list.';

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
        private ?\Closure $stored = null,
        ?MegaMenuContent $content = null
    ) {
        $this->menus   = $menus ?? new MenuSource();
        $this->posts   = $posts ?? new PostSource();
        $this->content = $content ?? new MegaMenuContent();
    }

    /** The owner's own texts and pictures per menu item (what the panels are built with besides the menu). */
    public function content(): MegaMenuContent
    {
        return $this->content;
    }

    /**
     * What the admin screen needs to know, without changing anything.
     *
     * `drift` is null when the mega menu matches the menu (or none is applied), else what differs: `{bar: {missing, extra, reordered}|null, items: list<{id, title, missing, extra, reordered}>, gone: list<int>}`
     * (`gone`: panels of items that no longer have sub-items or are no longer in the menu).
     *
     * @return array{header:?array{layout_id:int,title:string},menuModule:bool,applied:bool,menu:?array{id:int,name:string,items:int,panels:int,how:string},menus:list<array{id:int,name:string,items:int}>,items:list<array{id:int,title:string,layout:?string,layoutName:?string,content:bool,icons:bool,icon:string,children:list<array{id:int,title:string,icon:string}>,custom:bool,edited:bool,edits:string}>,drift:?array<string,mixed>}
     */
    public function status(): array
    {
        $header = $this->globalHeader();
        $state  = [ 'header' => $header, 'menuModule' => false, 'applied' => false, 'menu' => null, 'items' => [], 'drift' => null, 'menus' => [] ];
        if ( null === $header ) {
            return $state;
        }
        $info                = HeaderEdit::inspect( $this->storedContent( $header['layout_id'] ) );
        $state['menuModule'] = null !== $info['menu'];
        $state['menus']      = null === $info['menu'] ? [] : $this->menus->all();
        $state['applied']    = $info['hasMega'];
        $look                = null === $info['menu'] ? null : Look::fromMenuAttrs( $info['menu'] );
        $menu                = $this->menus->read( null === $look ? null : $look['menuId'] );
        if ( null !== $menu ) {
            $state['menu'] = [ 'id' => (int) $menu['menuId'], 'name' => $menu['name'], 'items' => count( $menu['tree'] ), 'panels' => MenuTree::panelCount( $menu['tree'] ), 'how' => $menu['how'] ];
            // Every top-level item that has a panel, and (when a mega menu is applied) the layout that panel was built from.
            $built  = $info['hasMega'] ? HeaderEdit::panels( $this->storedContent( $header['layout_id'] ) ) : [];
            $edited = $info['hasMega'] ? $this->editedIds( $this->storedContent( $header['layout_id'] ) ) : [];
            foreach ( $menu['tree'] as $item ) {
                if ( [] !== $item['children'] ) {
                    $layout           = isset( $built[ $item['id'] ] ) ? Layouts::find( $built[ $item['id'] ] ) : null;
                    $state['items'][] = [
                        'id'         => (int) $item['id'],
                        'title'      => (string) $item['title'],
                        'layout'     => null === $layout ? null : (string) $layout['id'],
                        'layoutName' => null === $layout ? null : (string) $layout['name'],
                        'content'    => null !== $layout && ! empty( $layout['content'] ),
                        'icons'      => null !== $layout && ! empty( $layout['icons'] ),
                        'icon'       => $this->content->get( (int) $item['id'] )['icon'],
                        'children'   => array_map( fn ( array $c ): array => [ 'id' => (int) $c['id'], 'title' => (string) $c['title'], 'icon' => $this->content->get( (int) $c['id'] )['icon'] ], $item['children'] ),
                        'custom'     => $this->content->hasContent( (int) $item['id'] ),
                        'edited'     => in_array( (int) $item['id'], $edited, true ),
                        'edits'      => $this->content->get( (int) $item['id'] )['edits'],
                    ];
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
        // A panel the owner edited by hand is theirs now: it is not "out of date", it just differs.
        $have = MenuDrift::signature( HeaderEdit::wrapper( $stored ) );
        $want = MenuDrift::signature( $wrapper );
        foreach ( $this->editedIds( $stored ) as $id ) {
            unset( $have[ $id ], $want[ $id ] );
        }
        $diff = MenuDrift::compare( $have, $want, $snapshots );
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
    public function apply( string $layoutId, bool $dry = false, ?int $itemId = null, ?string $edited = null ): array
    {
        $layout = Layouts::find( $layoutId );
        if ( null === $layout ) {
            throw new \InvalidArgumentException( 'Unknown mega menu layout "' . $layoutId . '".' );
        }
        $header = $this->requireHeader();
        $result = $this->writer->apply( $header['layout_id'], self::TOOL, function ( string $stored ) use ( $layout, $itemId, $edited, $dry ): array {
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
            [ $keep, $replaced ] = $this->guard( $stored, $menu, $itemId, $edited, $dry );

            return [
                'content' => HeaderEdit::apply( $stored, $this->wrapper( $info, $look, $menu, $layout, $byItem, $keep ) ),
                'extra'   => [ 'mega_menu' => [ 'layout' => $layout['id'], 'name' => $layout['name'], 'item' => $itemId, 'menu' => $menu['name'], 'menu_source' => $menu['how'], 'panels' => MenuTree::panelCount( $menu['tree'] ), 'edited_kept' => array_keys( $keep ), 'edited_replaced' => array_keys( $replaced ) ] ],
            ];
        }, $dry );
        return $result;
    }

    /**
     * Builds the mega menu again from the menu as it is now, keeping the layout each item's panel has (an item that had no
     * panel before gets the layout most panels have) and the owner's own content. The answer to "your menu changed".
     * `$edited` says what to do with panels edited in the builder whose own choice is "ask me": `keep` or `rebuild`.
     *
     * @return array<string,mixed> the LayoutWriter result (a dry run returns the preview)
     * @throws EditedPanelsException when a panel was edited in the builder and nobody said what to do with it (nothing is changed)
     */
    public function refresh( bool $dry = false, ?string $edited = null ): array
    {
        $header = $this->requireHeader();
        $result = $this->writer->apply( $header['layout_id'], self::TOOL, function ( string $stored ) use ( $edited, $dry ): array {
            [ $info, $look, $menu ] = $this->read( $stored );
            $layout                 = $this->commonLayout( $stored );
            if ( ! $info['hasMega'] || null === $layout ) {
                throw new \InvalidArgumentException( self::NOTHING_TO_UPDATE );
            }
            [ $keep, $replaced ] = $this->guard( $stored, $menu, null, $edited, $dry );

            return [
                'content' => HeaderEdit::apply( $stored, $this->wrapper( $info, $look, $menu, $layout, $this->builtLayouts( $stored, $menu ), $keep ) ),
                'extra'   => [ 'mega_menu' => [ 'layout' => $layout['id'], 'name' => $layout['name'], 'item' => null, 'refresh' => true, 'menu' => $menu['name'], 'menu_source' => $menu['how'], 'panels' => MenuTree::panelCount( $menu['tree'] ), 'edited_kept' => array_keys( $keep ), 'edited_replaced' => array_keys( $replaced ) ] ],
            ];
        }, $dry );
        return $result;
    }

    /**
     * Makes the header show another menu (its Menu module's `Menu` option, so the phone menu changes too) and, when a mega menu is on,
     * builds it again from that menu: every panel gets the layout most panels had, the owner's content stays stored for the items it
     * belongs to. Nothing changes when the menu is already the one shown.
     *
     * @return array<string,mixed> the LayoutWriter result (a dry run returns the preview)
     * @throws \InvalidArgumentException for a menu that does not exist, or (with a mega menu on) one without sub-items; nothing is changed
     */
    public function useMenu( int $menuId, bool $dry = false, ?string $edited = null ): array
    {
        if ( ! $this->menus->exists( $menuId ) ) {
            throw new \InvalidArgumentException( self::NO_SUCH_MENU );
        }
        $header = $this->requireHeader();

        return $this->writer->apply( $header['layout_id'], self::TOOL, function ( string $stored ) use ( $menuId, $edited, $dry ): array {
            $info = HeaderEdit::inspect( $stored );
            if ( null === $info['menu'] ) {
                throw new \InvalidArgumentException( HeaderEdit::NO_MENU );
            }
            $content = HeaderEdit::setMenu( $stored, $menuId );
            $name    = '';
            foreach ( $this->menus->all() as $m ) {
                if ( $m['id'] === $menuId ) {
                    $name = $m['name'];
                }
            }
            $extra = [ 'menu_id' => $menuId, 'menu' => $name, 'mega_menu_on' => $info['hasMega'] ];
            if ( $info['hasMega'] ) {
                [ $info2, $look, $menu ] = $this->read( $content );
                $layout                  = $this->commonLayout( $stored );
                if ( null === $layout ) {
                    throw new \InvalidArgumentException( self::NOTHING_TO_UPDATE );
                }
                [ $keep ] = $this->guard( $stored, $menu, null, $edited, $dry );
                $content  = HeaderEdit::apply( $content, $this->wrapper( $info2, $look, $menu, $layout, $this->builtLayouts( $stored, $menu ), $keep ) );
            }

            return [ 'content' => $content, 'extra' => [ 'mega_menu' => $extra ] ];
        }, $dry );
    }

    /** @return array<string,mixed> */
    public function remove( bool $dry = false ): array
    {
        $header = $this->requireHeader();

        $result = $this->writer->apply( $header['layout_id'], self::TOOL, static fn ( string $stored ): array => [ 'content' => HeaderEdit::remove( $stored ), 'extra' => [ 'mega_menu' => [ 'layout' => null ] ] ], $dry );
        return $result;
    }


    // ---- panels edited by hand ---------------------------------------------------------------------------------------

    /**
     * Item ids whose panel in the stored header differs from what this add-on wrote (the owner changed it in the Divi builder): the
     * fingerprint in the panel's own label no longer fits the panel. A panel without a fingerprint (written by an older version) is not
     * called edited.
     *
     * @return list<int>
     */
    private function editedIds( string $stored ): array
    {
        $out = [];
        foreach ( HeaderEdit::panelBlocks( $stored ) as $id => $markup ) {
            if ( PanelFingerprint::edited( $markup ) ) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * What to do with the panels edited in the builder, by each item's own choice (`ask` falls back to `$choice`). The item being given a
     * new layout on purpose (`$target`) is always rebuilt.
     *
     * @return array{0:array<int,string>,1:array<int,string>} the panels to keep (item id => stored markup) and the ones that will be replaced (item id => title)
     * @throws EditedPanelsException when a panel's choice is "ask" and none was given (never on a dry run: the preview shows the rebuild)
     */
    private function guard( string $stored, array $menu, ?int $target, ?string $choice, bool $dry ): array
    {
        $keep     = [];
        $replaced = [];
        $ask      = [];
        $markups  = HeaderEdit::panelBlocks( $stored );
        $titles   = [];
        foreach ( $menu['tree'] as $item ) {
            $titles[ (int) $item['id'] ] = (string) $item['title'];
        }
        foreach ( $this->editedIds( $stored ) as $id ) {
            if ( ! isset( $titles[ $id ] ) || ! $this->hasPanel( $menu, $id ) ) {
                continue; // the item is gone: its panel goes with it
            }
            if ( $id === $target ) {
                $replaced[ $id ] = $titles[ $id ];
                continue;
            }
            $policy = $this->content->get( $id )['edits'];
            if ( 'ask' === $policy && in_array( $choice, [ 'keep', 'rebuild' ], true ) ) {
                $policy = $choice;
            }
            if ( 'keep' === $policy && isset( $markups[ $id ] ) ) {
                $keep[ $id ] = $markups[ $id ];
            } elseif ( 'ask' === $policy && ! $dry ) {
                $ask[ $id ] = $titles[ $id ];
            } else {
                $replaced[ $id ] = $titles[ $id ];
            }
        }
        if ( [] !== $ask ) {
            throw new EditedPanelsException( $ask );
        }

        return [ $keep, $replaced ];
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
     * `$layout`. The layouts that show posts get the newest ones. `$keep` maps an item id to a panel's markup that goes in unchanged.
     *
     * @param array<string,mixed> $info
     * @param array<string,mixed> $look
     * @param array<string,mixed> $menu
     * @param array<string,mixed> $layout
     * @param array<int,array<string,mixed>> $byItem
     */
    private function wrapper( array $info, array $look, array $menu, array $layout, array $byItem, array $keep = [] ): string
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
            [ 'look' => $look, 'posts' => $posts, 'featureImage' => $image ? $this->featureImage() : '', 'content' => $this->content->forBuilder(), 'keep' => $keep ]
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
