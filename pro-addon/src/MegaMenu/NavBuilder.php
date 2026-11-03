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
     * @param array<string,mixed>      $layout one entry of Layouts::all()
     * @param list<array<string,mixed>> $tree   MenuTree::build()
     * @param array<string,mixed>      $ctx    `look` (Look::fromMenuAttrs), `posts` (item id => posts), `featureImage`
     */
    public function build( array $layout, array $tree, array $ctx ): string
    {
        if ( [] === $tree ) {
            throw new \InvalidArgumentException( 'The menu has no items.' );
        }
        $look = $ctx['look'] ?? [ 'font' => Look::DEFAULT_FONT, 'hoverColor' => null, 'logo' => null, 'logoWidth' => null ];
        $font = $look['font'];

        $links = '';
        foreach ( $tree as $item ) {
            $inner = [] === $item['children']
                ? ''
                : $this->panels->dropdown( $layout, $item, [ 'look' => $look, 'posts' => $ctx['posts'][ $item['id'] ] ?? [], 'featureImage' => $ctx['featureImage'] ?? '' ] );
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
