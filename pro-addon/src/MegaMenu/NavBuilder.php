<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds the whole desktop navigation as one wrapper group: a copy of the header's logo and the nav (a Link per top-level
 * menu item, with a Dropdown panel inside every item that has sub-items). The wrapper carries the admin label
 * `JHMG Mega Menu` so HeaderEdit can find it again, and is hidden on tablet and phone (they keep the native Menu module).
 * Pure.
 */
final class NavBuilder
{
    private PanelBuilder $panels;

    public function __construct( private Blocks $b, ?PanelBuilder $panels = null )
    {
        $this->panels = $panels ?? new PanelBuilder( $b );
    }

    /**
     * The same layout for every item with sub-items.
     *
     * @param array<string,mixed>      $layout one entry of Layouts::all()
     * @param list<array<string,mixed>> $tree   MenuTree::build()
     * @param array<string,mixed>      $ctx    `look` (Look::fromMenuAttrs), `posts` (item id => posts), `featureImage`
     */
    public function build( array $layout, array $tree, array $ctx ): string
    {
        return $this->buildMixed( [], $layout, $tree, $ctx );
    }

    /**
     * A layout per item: `$byItem` maps a top-level item id to its layout, an item not in it takes `$fallback`.
     *
     * @param array<int,array<string,mixed>> $byItem   item id => one entry of Layouts::all()
     * @param array<string,mixed>            $fallback one entry of Layouts::all()
     * @param list<array<string,mixed>>      $tree     MenuTree::build()
     * @param array<string,mixed>            $ctx      as for build()
     */
    public function buildMixed( array $byItem, array $fallback, array $tree, array $ctx ): string
    {
        if ( [] === $tree ) {
            throw new \InvalidArgumentException( 'The menu has no items.' );
        }
        $look = $ctx['look'] ?? [ 'font' => Look::DEFAULT_FONT, 'hoverColor' => null, 'logo' => null, 'logoWidth' => null ];
        $font = $look['font'];

        $links = '';
        foreach ( $tree as $item ) {
            // `keep`: a panel the owner edited in the builder and chose to keep goes back in exactly as it is.
            $inner = [] === $item['children']
                ? ''
                : ( $ctx['keep'][ $item['id'] ] ?? $this->panels->dropdown( $byItem[ $item['id'] ] ?? $fallback, $item, [ 'look' => $look, 'posts' => $ctx['posts'][ $item['id'] ] ?? [], 'featureImage' => $ctx['featureImage'] ?? '', 'own' => $ctx['content'][ $item['id'] ] ?? null ] ) );
            $links .= $this->b->link(
                $item['title'],
                $item['url'],
                $font,
                $look['hoverColor'] ?? Palette::tone( 'light' )['accent'],
                $inner,
                Blocks::padding( [ '10px', '0px', '10px', '0px' ] ),
                $item['newTab']
            );
        }
        $nav = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '28px', 'alignItems' => 'center' ], $links );

        $logo = '';
        if ( is_array( $look['logo'] ?? null ) ) {
            $l = $look['logo'];
            $logo = $this->b->group(
                [ 'display' => 'flex', 'flexDirection' => 'column', 'justifyContent' => 'center' ],
                $this->b->image( $l['src'], $l['alt'] ?? '', $l['linkUrl'] ?? null, [], $l['titleText'] ?? null, $l['id'] ?? null ),
                Blocks::sizing( [ 'width' => $look['logoWidth'] ?? '160px', 'maxWidth' => '100%' ] )
            );
        }

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'row', 'justifyContent' => 'space-between', 'alignItems' => 'center', 'columnGap' => '32px' ],
            $logo . $nav,
            array_merge( Blocks::sizing( [ 'width' => '100%' ] ), Blocks::zIndex( 999 ), Blocks::disabledOn( [ 'tablet' => 'on', 'phone' => 'on' ] ) ),
            Blocks::ADMIN_LABEL
        );
    }
}
