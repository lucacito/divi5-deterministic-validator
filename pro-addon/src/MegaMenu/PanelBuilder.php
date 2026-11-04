<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds the Dropdown panel of one top-level menu item for one layout. Pure: menu item + layout definition in, block
 * markup out (through `Blocks`, the only block writer).
 *
 * Context (`$ctx`): `look` (from Look::fromMenuAttrs), `posts` (the newest posts for this item: title, url, image, date)
 * and `featureImage` (a URL, only for the banner layout).
 */
final class PanelBuilder
{
    /** Font Awesome solid "arrow-right": one neutral glyph, so no icon is ever paired with a title it does not fit. */
    public const GLYPH = '&#xf061;';

    private const VIEW_ALL = 'View all';

    /** @var array<string,string> */
    private array $t = [];

    /** Width in px the panel's content may use (the panel width less its padding); columns share it so long titles wrap. */
    private int $avail = 0;

    public function __construct( private Blocks $b )
    {
    }

    /**
     * @param array<string,mixed> $layout one entry of Layouts::all()
     * @param array<string,mixed> $item   a top-level menu item that has children
     * @param array<string,mixed> $ctx
     */
    public function dropdown( array $layout, array $item, array $ctx ): string
    {
        $this->t = Palette::tone( (string) $layout['tone'] );
        $viewport = 'viewport' === $layout['mode'];
        $this->avail = (int) $layout['width'] - ( $viewport ? 48 : 40 );
        $inner    = $this->content( $layout, $item, $ctx );
        $sizing   = $viewport
            ? [ 'width' => '100%', 'maxWidth' => $layout['width'] . 'px', 'alignment' => 'center' ]
            : [ 'width' => $layout['width'] . 'px', 'maxWidth' => '100%' ];
        $wrapper  = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '16px' ], $inner, Blocks::sizing( $sizing ) );

        $deco = array_merge(
            Blocks::background( $this->t['bg'] ),
            $viewport
                ? array_merge( Blocks::padding( [ '32px', '24px', '32px', '24px' ] ), Blocks::border( '0px', [ '1px', $this->t['line'] ] ) )
                : array_merge( Blocks::padding( [ '20px', '20px', '20px', '20px' ] ), Blocks::border( '14px', [ '1px', $this->t['line'] ] ) ),
            Blocks::shadow( '12px', '32px', $this->t['shadow'] )
        );
        $settings = [
            'showOn'    => 'hover',
            'direction' => 'below',
            'alignment' => $viewport ? 'center' : 'end',
            'offset'    => $viewport ? '24px' : '14px',
            'position'  => 'floating',
        ];
        if ( $viewport ) {
            $settings['horizontalMode'] = 'viewport';
        }

        return $this->b->dropdown( $settings, $wrapper, $deco, 'click' );
    }

    // ---- families --------------------------------------------------------------------------------------------------

    private function content( array $layout, array $item, array $ctx ): string
    {
        return match ( $layout['family'] ) {
            'list'    => $this->listFamily( $layout, $item ),
            'columns' => $this->columnsFamily( $layout, $item ),
            'feature' => $this->featureFamily( $layout, $item, $ctx ),
            'icons'   => $this->iconsFamily( $layout, $item ),
            'latest'  => $this->latestFamily( $layout, $item, $ctx ),
            default   => throw new \InvalidArgumentException( 'Unknown mega menu family: ' . (string) $layout['family'] ),
        };
    }

    private function listFamily( array $layout, array $item ): string
    {
        $rows = array_map( fn ( array $c ): string => $this->row( $c, (string) $layout['rows'], true ), $item['children'] );
        if ( (int) $layout['columns'] > 1 ) {
            return $this->b->group( [ 'display' => 'grid', 'gridColumnCount' => (string) $layout['columns'], 'columnGap' => '24px', 'rowGap' => '4px' ], implode( '', $rows ) );
        }

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '4px' ], implode( '', $rows ) );
    }

    private function columnsFamily( array $layout, array $item ): string
    {
        $out = $this->columns( ColumnPlan::from( $item, (int) $layout['columns'] ), (string) $layout['rows'] );
        if ( $layout['footer'] ) {
            $out .= $this->footer( $item );
        }

        return $out;
    }

    private function featureFamily( array $layout, array $item, array $ctx ): string
    {
        // A side card (240 px) and the gap beside it take room from the columns.
        $saved = $this->avail;
        if ( in_array( $layout['feature'], [ 'right', 'left' ], true ) ) {
            $this->avail -= 272;
        }
        $cols = $this->columns( ColumnPlan::from( $item, (int) $layout['columns'] ), (string) $layout['rows'] );
        $this->avail = $saved;
        switch ( $layout['feature'] ) {
            case 'right':
                return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '32px', 'alignItems' => 'stretch' ], $cols . $this->card( $item ) );
            case 'left':
                return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '32px', 'alignItems' => 'stretch' ], $this->card( $item ) . $cols );
            case 'top':
                return $this->banner( $item, (string) ( $ctx['featureImage'] ?? '' ) ) . $cols;
            default: // cta
                return $cols . $this->ctaBar( $item );
        }
    }

    private function iconsFamily( array $layout, array $item ): string
    {
        if ( 'cards' === $layout['rows'] ) {
            $cards = array_map( fn ( array $c ): string => $this->row( $c, 'cards', true ), $item['children'] );
            $grid  = $this->b->group( [ 'display' => 'grid', 'gridColumnCount' => (string) $layout['columns'], 'columnGap' => '12px', 'rowGap' => '12px' ], implode( '', $cards ) );

            return $grid . ( $layout['footer'] ? $this->footer( $item ) : '' );
        }
        if ( 'icons' === $layout['rows'] ) {
            return $this->columns( ColumnPlan::from( $item, (int) $layout['columns'] ), 'icons' );
        }
        $rows = array_map( fn ( array $c ): string => $this->row( $c, 'desc', true, true ), $item['children'] );

        return $this->b->group( [ 'display' => 'grid', 'gridColumnCount' => (string) $layout['columns'], 'columnGap' => '32px', 'rowGap' => '16px' ], implode( '', $rows ) );
    }

    private function latestFamily( array $layout, array $item, array $ctx ): string
    {
        $posts = array_slice( $ctx['posts'] ?? [], 0, (int) $layout['posts'] );
        $links = implode( '', array_map( fn ( array $c ): string => $this->row( $c, 'plain', true ), $item['children'] ) );
        $list  = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '4px', 'alignItems' => 'stretch' ], $links, Blocks::sizing( [ 'width' => '200px', 'maxWidth' => '100%' ] ) );

        switch ( $layout['postStyle'] ) {
            case 'withlinks':
                $cards = implode( '', array_map( fn ( array $p ): string => $this->postCard( $p, true ), $posts ) );

                return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '28px', 'alignItems' => 'flex-start' ], $list . $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '16px' ], $cards ) );
            case 'featured':
                if ( [] === $posts ) {
                    return $this->noPosts( $item, $links );
                }
                $big   = $this->postCard( $posts[0], true, true );
                $small = implode( '', array_map( fn ( array $p ): string => $this->postRow( $p ), array_slice( $posts, 1 ) ) );

                return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '28px', 'alignItems' => 'flex-start' ], $big . $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '12px' ], $small, Blocks::sizing( [ 'width' => '260px', 'maxWidth' => '100%' ] ) ) ) . $this->footer( $item, true );
            case 'compact':
                $rows = implode( '', array_map( fn ( array $p ): string => $this->postRow( $p, true ), $posts ) );

                return $this->b->group( [ 'display' => 'grid', 'gridColumnCount' => (string) max( 1, (int) $layout['columns'] ), 'columnGap' => '20px', 'rowGap' => '14px' ], $rows ) . $this->footer( $item, true );
            default: // cards
                if ( [] === $posts ) {
                    return $this->noPosts( $item, $links );
                }
                $cards = implode( '', array_map( fn ( array $p ): string => $this->postCard( $p, true ), $posts ) );

                return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '16px' ], $cards ) . $this->footer( $item, true );
        }
    }

    /** No post exists yet: show the item's own links so the panel is never empty. */
    private function noPosts( array $item, string $links ): string
    {
        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '4px' ], $links ) . $this->footer( $item );
    }

    // ---- pieces ----------------------------------------------------------------------------------------------------

    /** @param list<list<array<string,mixed>>> $columns */
    private function columns( array $columns, string $rows ): string
    {
        // Each column gets an explicit width: a flex column that grows from its longest title would push past the panel.
        $n      = max( 1, count( $columns ) );
        $colPx  = max( 120, intdiv( $this->avail - 36 * ( $n - 1 ), $n ) );
        $cols = [];
        foreach ( $columns as $sections ) {
            $inner = '';
            foreach ( $sections as $section ) {
                if ( null !== $section['heading'] ) {
                    $h      = $section['heading'];
                    $inner .= $this->b->link( $h['title'], $h['url'], [ 'size' => '12px', 'weight' => '700', 'color' => $this->t['muted'] ], $this->t['accent'], '', [], $h['newTab'] );
                }
                foreach ( $section['links'] as $l ) {
                    $inner .= $this->row( $l, $rows, false );
                }
            }
            $cols[] = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '6px', 'alignItems' => 'flex-start' ], $inner, Blocks::sizing( [ 'width' => $colPx . 'px', 'maxWidth' => '100%', 'size' => [ 'flexGrow' ] ] ) );
        }

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '36px', 'alignItems' => 'flex-start' ], implode( '', $cols ) );
    }

    /** One link row in the style of the layout. `$withSub` also writes the item's own sub-links, indented. */
    private function row( array $it, string $style, bool $withSub, bool $bare = false ): string
    {
        $title = $this->b->link( $it['title'], $it['url'], [ 'size' => '15px', 'weight' => in_array( $style, [ 'plain', 'icons' ], true ) ? '500' : '600', 'color' => $this->t['text'] ], $this->t['accent'], '', [], $it['newTab'] );
        $desc  = '' !== $it['description'] ? $this->b->text( '<p>' . self::esc( $it['description'] ) . '</p>', [ 'size' => '13px', 'color' => $this->t['muted'], 'lineHeight' => '1.45em' ] ) : '';
        $sub   = '';
        if ( $withSub && [] !== $it['children'] ) {
            $links = '';
            foreach ( ColumnPlan::flatten( $it['children'] ) as $s ) {
                $links .= $this->b->link( $s['title'], $s['url'], [ 'size' => '14px', 'weight' => '400', 'color' => $this->t['muted'] ], $this->t['accent'], '', [], $s['newTab'] );
            }
            $sub = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '2px' ], $links, Blocks::padding( [ '0px', '0px', '0px', '12px' ] ) );
        }

        switch ( $style ) {
            case 'desc':
                return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '4px' ], $title . $desc . $sub, $bare ? [] : Blocks::padding( [ '6px', '0px', '6px', '0px' ] ) );
            case 'icons':
                return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '4px' ], $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '12px', 'alignItems' => 'center' ], $this->chip() . $title ) . $sub );
            case 'cards':
                return $this->b->group(
                    [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '10px', 'alignItems' => 'flex-start' ],
                    $this->chip() . $title . $desc . $sub,
                    array_merge( Blocks::background( $this->t['card'] ), Blocks::padding( [ '16px', '16px', '16px', '16px' ] ), Blocks::border( '12px', [ '1px', $this->t['line'] ] ) )
                );
            default: // plain
                return [] === $sub ? $title : $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '2px' ], $title . $sub );
        }
    }

    /** The round icon tile. */
    private function chip(): string
    {
        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'alignItems' => 'center', 'justifyContent' => 'center' ],
            $this->b->icon( self::GLYPH, $this->t['accent'], '14px' ),
            array_merge( Blocks::background( $this->t['tint'] ), Blocks::border( '999px' ), Blocks::sizing( [ 'width' => '34px', 'height' => '34px' ] ) )
        );
    }

    /** The "view all" link, and with `$withLinks` also every sub-link of the item, so a panel of posts never hides them. */
    private function footer( array $item, bool $withLinks = false ): string
    {
        $links = $this->b->link( self::VIEW_ALL . ' ' . $item['title'], $item['url'], [ 'size' => '14px', 'weight' => '600', 'color' => $this->t['accent'] ], $this->t['text'] );
        if ( $withLinks ) {
            foreach ( ColumnPlan::flatten( $item['children'] ) as $s ) {
                $links .= $this->b->link( $s['title'], $s['url'], [ 'size' => '14px', 'weight' => '500', 'color' => $this->t['muted'] ], $this->t['accent'], '', [], $s['newTab'] );
            }
        }

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'flexWrap' => 'wrap', 'columnGap' => '24px', 'rowGap' => '6px', 'alignItems' => 'center' ], $links, array_merge( Blocks::padding( [ '14px', '0px', '0px', '0px' ] ), Blocks::topRule( $this->t['line'] ) ) );
    }

    private function card( array $item ): string
    {
        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '10px', 'alignItems' => 'flex-start', 'justifyContent' => 'center' ],
            $this->cardBody( $item ),
            array_merge( Blocks::background( $this->t['card'] ), Blocks::padding( [ '22px', '22px', '22px', '22px' ] ), Blocks::border( '12px', [ '1px', $this->t['line'] ] ), Blocks::sizing( [ 'width' => '240px', 'maxWidth' => '100%' ] ) )
        );
    }

    private function cardBody( array $item ): string
    {
        $text = '' !== $item['description'] ? $item['description'] : 'Everything under ' . $item['title'] . ' in one place.';

        return $this->b->heading( $item['title'], 'h3', [ 'size' => '20px', 'weight' => '700', 'color' => $this->t['text'], 'lineHeight' => '1.25em' ] )
            . $this->b->text( '<p>' . self::esc( $text ) . '</p>', [ 'size' => '14px', 'color' => $this->t['muted'], 'lineHeight' => '1.5em' ] )
            . $this->b->button( self::VIEW_ALL, $item['url'], [ 'size' => '14px', 'weight' => '600' ] );
    }

    private function banner( array $item, string $image ): string
    {
        $pic = '' !== $image ? $this->b->image( $image, '', null, array_merge( Blocks::border( '10px' ), Blocks::sizing( [ 'width' => '100%' ] ) ) ) : '';

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '24px', 'alignItems' => 'center' ],
            ( '' !== $pic ? $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column' ], $pic, Blocks::sizing( [ 'width' => '220px', 'maxWidth' => '100%' ] ) ) : '' )
                . $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '8px', 'alignItems' => 'flex-start' ], $this->cardBody( $item ) ),
            array_merge( Blocks::background( $this->t['card'] ), Blocks::padding( [ '18px', '18px', '18px', '18px' ] ), Blocks::border( '12px', [ '1px', $this->t['line'] ] ) )
        );
    }

    private function ctaBar( array $item ): string
    {
        $text = '' !== $item['description'] ? $item['description'] : 'See everything under ' . $item['title'] . '.';
        $body = $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '2px' ],
            $this->b->link( self::VIEW_ALL . ' ' . $item['title'], $item['url'], [ 'size' => '16px', 'weight' => '700', 'color' => $this->t['text'] ], $this->t['accent'] )
            . $this->b->text( '<p>' . self::esc( $text ) . '</p>', [ 'size' => '14px', 'color' => $this->t['muted'] ] )
        );

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'row', 'justifyContent' => 'space-between', 'alignItems' => 'center' ],
            $body . $this->chip(),
            array_merge( Blocks::background( $this->t['card'] ), Blocks::padding( [ '14px', '18px', '14px', '18px' ] ), Blocks::border( '12px', [ '1px', $this->t['line'] ] ) )
        );
    }

    private function postCard( array $p, bool $withImage, bool $large = false ): string
    {
        $pic  = $withImage && '' !== (string) ( $p['image'] ?? '' ) ? $this->b->image( (string) $p['image'], '', null, array_merge( Blocks::border( '10px' ), Blocks::sizing( [ 'width' => '100%' ] ) ) ) : '';
        $body = $this->b->link( (string) $p['title'], (string) $p['url'], [ 'size' => $large ? '18px' : '15px', 'weight' => '600', 'color' => $this->t['text'] ], $this->t['accent'] )
            . ( '' !== (string) ( $p['date'] ?? '' ) ? $this->b->text( '<p>' . self::esc( (string) $p['date'] ) . '</p>', [ 'size' => '13px', 'color' => $this->t['muted'] ] ) : '' );

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '8px' ], $pic . $body, Blocks::sizing( [ 'size' => [ 'flexGrow' ], 'width' => $large ? '300px' : '200px', 'maxWidth' => '100%' ] ) );
    }

    private function postRow( array $p, bool $thumb = false ): string
    {
        $pic  = $thumb && '' !== (string) ( $p['image'] ?? '' ) ? $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column' ], $this->b->image( (string) $p['image'], '', null, Blocks::border( '8px' ) ), Blocks::sizing( [ 'width' => '64px', 'maxWidth' => '100%' ] ) ) : '';
        $text = $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '2px' ],
            $this->b->link( (string) $p['title'], (string) $p['url'], [ 'size' => '15px', 'weight' => '600', 'color' => $this->t['text'] ], $this->t['accent'] )
            . ( '' !== (string) ( $p['date'] ?? '' ) ? $this->b->text( '<p>' . self::esc( (string) $p['date'] ) . '</p>', [ 'size' => '13px', 'color' => $this->t['muted'] ] ) : '' )
        );

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '12px', 'alignItems' => 'center' ], $pic . $text );
    }

    private static function esc( string $s ): string
    {
        return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}
