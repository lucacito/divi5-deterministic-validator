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

    /** How many cards of this panel have been given their entrance so far: each starts 70 ms after the one before (at most 5 steps). */
    private int $entered = 0;

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
        $this->t       = Palette::tone( (string) $layout['tone'] );
        $this->entered = 0;
        $viewport = 'viewport' === $layout['mode'];
        $this->avail = (int) $layout['width'] - ( $viewport ? 0 : 40 );
        $inner    = $this->content( $layout, $item, $ctx );
        // A full-width panel keeps its content as wide as Divi's own row (80% of the window, at most 1080 px), so it lines up
        // with the logo and the navigation above it.
        $sizing   = $viewport
            ? [ 'width' => '80%', 'maxWidth' => $layout['width'] . 'px', 'alignment' => 'center' ]
            : [ 'width' => $layout['width'] . 'px', 'maxWidth' => '100%' ];
        // The panel's own ground: a faint top-to-bottom gradient, a pale wash, or (behind the showcase tiles) a tinted gradient.
        // Text never sits on a translucent ground, only on an opaque tile, the intro panel or the promo card.
        $ground   = match ( (string) $layout['backdrop'] ) {
            'accent' => Blocks::gradient( $this->t['backdrop'], $this->t['backdropTo'], '135deg' ),
            'wash'   => Blocks::background( $this->t['wash'] ),
            default  => Blocks::gradient( $this->t['panelFrom'], $this->t['panelTo'], '180deg' ),
        };
        $wrapper  = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '16px' ], $inner, Blocks::sizing( $sizing ) );

        // A 3px accent edge along the top, a hairline round the rest.
        $deco = array_merge(
            $ground,
            $viewport
                ? array_merge( Blocks::padding( [ '32px', '0px', '32px', '0px' ] ), Blocks::border( '0px', [ '1px', $this->t['line'] ], [ '3px', $this->t['accent'] ] ) )
                : array_merge( Blocks::padding( [ '22px', '22px', '22px', '22px' ] ), Blocks::border( '18px', [ '1px', $this->t['line'] ], [ '3px', $this->t['accent'] ] ) ),
            Blocks::shadow( '22px', '56px', $this->t['shadow'] )
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
            'columns' => $this->columnsFamily( $layout, $item ),
            'feature' => $this->featureFamily( $layout, $item, $ctx ),
            'icons'   => $this->iconsFamily( $layout, $item ),
            'latest'  => $this->latestFamily( $layout, $item, $ctx ),
            'showcase' => $this->showcaseFamily( $layout, $item ),
            default   => throw new \InvalidArgumentException( 'Unknown mega menu family: ' . (string) $layout['family'] ),
        };
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
            default: // top
                return $this->banner( $item, (string) ( $ctx['featureImage'] ?? '' ) ) . $cols;
        }
    }

    private function iconsFamily( array $layout, array $item ): string
    {
        $cards = array_map( fn ( array $c ): string => $this->row( $c, 'cards', true ), $item['children'] );
        $grid  = $this->b->group( [ 'display' => 'grid', 'gridColumnCount' => (string) self::gridColumns( count( $cards ), (int) $layout['columns'] ), 'columnGap' => '14px', 'rowGap' => '14px' ], implode( '', $cards ) );

        return $grid . ( $layout['footer'] ? $this->footer( $item ) : '' );
    }

    private function latestFamily( array $layout, array $item, array $ctx ): string
    {
        $posts = array_slice( $ctx['posts'] ?? [], 0, (int) $layout['posts'] );
        $links = implode( '', array_map( fn ( array $c ): string => $this->row( $c, 'plain', true ), $item['children'] ) );

        if ( 'withlinks' === $layout['postStyle'] ) {
            // The links sit in a tinted card of their own, the newest posts beside it.
            $list  = $this->b->group(
                [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '6px', 'alignItems' => 'stretch' ],
                $links,
                array_merge(
                    Blocks::background( $this->t['card'] ),
                    Blocks::padding( [ '18px', '18px', '18px', '18px' ] ),
                    Blocks::border( '16px', [ '1px', $this->t['line'] ] ),
                    Blocks::sizing( [ 'width' => '210px', 'maxWidth' => '100%' ] )
                )
            );
            $cards = implode( '', array_map( fn ( array $p ): string => $this->postCard( $p ), $posts ) );

            return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '20px', 'alignItems' => 'flex-start' ], $list . $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '16px' ], $cards ) );
        }
        if ( [] === $posts ) {
            return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '4px' ], $links ) . $this->footer( $item );
        }
        $cards = implode( '', array_map( fn ( array $p ): string => $this->postCard( $p ), $posts ) );

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '16px' ], $cards ) . $this->footer( $item, true );
    }

    /**
     * The showcase family: link columns as ruled tiles (or open columns), optionally beside a coloured intro panel. The tiles
     * are opaque so text never sits on a translucent backdrop.
     */
    private function showcaseFamily( array $layout, array $item ): string
    {
        $columns = ColumnPlan::from( $item, (int) $layout['columns'] );
        $tiles   = 'cards' === $layout['surface'];
        $gap     = $tiles ? '14px' : '36px';
        $cells   = (bool) $layout['intro'] ? $this->introPanel( $item ) : '';
        foreach ( $columns as $sections ) {
            $cells .= $this->tile( $sections, $tiles );
        }

        // One grid with a track per tile (and one for the intro panel): the tracks share the panel width equally, so nothing
        // can run past the edge and a menu with fewer groups than the layout's column count leaves no empty slot.
        $count = count( $columns ) + ( (bool) $layout['intro'] ? 1 : 0 );

        return $this->b->group( [ 'display' => 'grid', 'gridColumnCount' => (string) max( 1, $count ), 'columnGap' => $gap, 'rowGap' => $gap ], $cells );
    }

    /** One link column of the showcase family. @param list<list<array<string,mixed>>> $sections */
    private function tile( array $sections, bool $tiles ): string
    {
        $inner = '';
        foreach ( $sections as $at => $section ) {
            $headed = null !== $section['heading'];
            if ( $headed ) {
                $h      = $section['heading'];
                $inner .= $this->b->group(
                    [ 'display' => 'flex', 'flexDirection' => 'row', 'justifyContent' => 'space-between', 'alignItems' => 'center', 'columnGap' => '12px' ],
                    $this->b->link( $h['title'], $h['url'], [ 'size' => '17px', 'weight' => '700', 'color' => $this->t['text'] ], $this->t['accent'], '', [], $h['newTab'] )
                    . $this->chip( 24, '11px' ),
                    Blocks::padding( [ 0 === $at ? '0px' : '16px', '0px', '12px', '0px' ] )
                );
            }
            foreach ( $section['links'] as $i => $l ) {
                $inner .= $this->ruledRow( $l, $headed || $i > 0 );
            }
        }

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '0px', 'alignItems' => 'stretch' ],
            $inner,
            $tiles ? $this->surfaceCard( [ '20px', '20px', '12px', '20px' ] ) : []
        );
    }

    /** A link with a hairline above it (the first row of a headed tile draws the rule under the heading). */
    private function ruledRow( array $it, bool $rule ): string
    {
        $title = $this->b->link( $it['title'], $it['url'], [ 'size' => '15px', 'weight' => '500', 'color' => $this->t['text'] ], $this->t['accent'], '', [], $it['newTab'] );
        $desc  = '' !== $it['description'] ? $this->b->text( '<p>' . self::esc( $it['description'] ) . '</p>', [ 'size' => '13px', 'color' => $this->t['muted'], 'lineHeight' => '1.45em' ] ) : '';

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '2px' ],
            $title . $desc,
            array_merge( Blocks::padding( [ '10px', '0px', '10px', '0px' ] ), $rule ? Blocks::topRule( $this->t['line'] ) : [] )
        );
    }

    /** The coloured intro panel: the item's title, one line about it, and the way to its page. */
    private function introPanel( array $item ): string
    {
        $text = '' !== $item['description'] ? $item['description'] : 'Everything under ' . $item['title'] . ' in one place.';
        $link = $this->b->link( self::VIEW_ALL . ' ' . $item['title'], $item['url'], [ 'size' => '15px', 'weight' => '700', 'color' => $this->t['onAccent'] ], $this->t['onAccentHover'] );
        $chip = $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'alignItems' => 'center', 'justifyContent' => 'center' ],
            $this->b->icon( self::GLYPH, $this->t['gradFrom'], '14px' ),
            array_merge( Blocks::background( $this->t['onAccent'] ), Blocks::border( '999px' ), Blocks::sizing( [ 'width' => '36px', 'height' => '36px' ] ) )
        );

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '14px', 'alignItems' => 'flex-start', 'justifyContent' => 'space-between' ],
            $this->b->group(
                [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '10px' ],
                $this->b->heading( $item['title'], 'h3', [ 'size' => '30px', 'weight' => '700', 'color' => $this->t['onAccent'], 'lineHeight' => '1.15em' ] )
                . $this->b->text( '<p>' . self::esc( $text ) . '</p>', [ 'size' => '15px', 'color' => $this->t['onAccent'], 'lineHeight' => '1.5em' ] )
            )
            . $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'flexWrap' => 'wrap', 'columnGap' => '12px', 'rowGap' => '10px', 'alignItems' => 'center' ], $chip . $link ),
            array_merge(
                Blocks::gradient( $this->t['gradFrom'], $this->t['gradTo'] ),
                Blocks::padding( [ '28px', '28px', '28px', '28px' ] ),
                Blocks::border( '16px' ),
                Blocks::shadow( '14px', '32px', $this->t['glow'], [ '20px', '40px', $this->t['glow'] ] ),
                Blocks::lift(),
                $this->enter()
            )
        );
    }

    /** Columns for a grid of `$count` cards so the last row is never a single card when a neighbouring count fills it evenly. */
    private static function gridColumns( int $count, int $wanted ): int
    {
        if ( $count <= $wanted || 0 === $count % $wanted ) {
            return $wanted;
        }
        foreach ( [ $wanted + 1, $wanted - 1 ] as $try ) {
            if ( $try >= 2 && $try <= 4 && 0 === $count % $try ) {
                return $try;
            }
        }

        return $wanted;
    }

    // ---- pieces ----------------------------------------------------------------------------------------------------

    /** The entrance of the next card. */
    private function enter(): array
    {
        return Blocks::enter( ( 70 * min( 5, $this->entered++ ) ) . 'ms' );
    }

    /** An opaque card: its own surface colour, a hairline, a soft shadow, and on hover it rises, glows and takes the accent as its frame. @param array{0:string,1:string,2:string,3:string} $padding */
    private function surfaceCard( array $padding, string $radius = '16px' ): array
    {
        return array_merge(
            Blocks::background( $this->t['surface'] ),
            Blocks::padding( $padding ),
            Blocks::border( $radius, [ '1px', $this->t['line'] ], null, $this->t['accent'] ),
            Blocks::shadow( '4px', '16px', $this->t['soft'], [ '14px', '30px', $this->t['glow'] ] ),
            Blocks::lift(),
            $this->enter()
        );
    }

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
                    $inner .= $this->b->group(
                        [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '8px', 'alignItems' => 'flex-start' ],
                        $this->b->link( $h['title'], $h['url'], [ 'size' => '14px', 'weight' => '700', 'color' => $this->t['accent'] ], $this->t['text'], '', [], $h['newTab'] )
                        . $this->bar(),
                        Blocks::padding( [ '0px', '0px', '4px', '0px' ] )
                    );
                }
                foreach ( $section['links'] as $l ) {
                    $inner .= $this->row( $l, $rows, false );
                }
            }
            $cols[] = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '6px', 'alignItems' => 'flex-start' ], $inner, Blocks::sizing( [ 'width' => $colPx . 'px', 'maxWidth' => '100%', 'size' => [ 'flexGrow' ] ] ) );
        }

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '36px', 'alignItems' => 'flex-start' ], implode( '', $cols ) );
    }

    /** A short gradient bar under a column heading. */
    private function bar(): string
    {
        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'row' ],
            '',
            array_merge( Blocks::gradient( $this->t['gradFrom'], $this->t['gradTo'], '90deg' ), Blocks::border( '999px' ), Blocks::sizing( [ 'width' => '32px', 'height' => '3px' ] ) )
        );
    }

    /** One link row in the style of the layout. `$withSub` also writes the item's own sub-links, indented. */
    private function row( array $it, string $style, bool $withSub ): string
    {
        $title = $this->b->link( $it['title'], $it['url'], [ 'size' => 'cards' === $style ? '16px' : '15px', 'weight' => 'cards' === $style ? '700' : '500', 'color' => $this->t['text'] ], $this->t['accent'], '', [], $it['newTab'] );
        $desc  = '' !== $it['description'] ? $this->b->text( '<p>' . self::esc( $it['description'] ) . '</p>', [ 'size' => '13px', 'color' => $this->t['muted'], 'lineHeight' => '1.45em' ] ) : '';
        $sub   = '';
        if ( $withSub && [] !== $it['children'] ) {
            $links = '';
            foreach ( ColumnPlan::flatten( $it['children'] ) as $s ) {
                $links .= $this->b->link( $s['title'], $s['url'], [ 'size' => '14px', 'weight' => '400', 'color' => $this->t['muted'] ], $this->t['accent'], '', [], $s['newTab'] );
            }
            $sub = $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '2px' ], $links, Blocks::padding( [ '0px', '0px', '0px', '12px' ] ) );
        }

        if ( 'cards' === $style ) {
            return $this->b->group(
                [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '10px', 'alignItems' => 'flex-start' ],
                $this->chip( 40, '15px' ) . $title . $desc . $sub,
                $this->surfaceCard( [ '18px', '18px', '18px', '18px' ] )
            );
        }

        return [] === $sub ? $title : $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '2px' ], $title . $sub );
    }

    /** The round gradient chip with an arrow. */
    private function chip( int $size = 34, string $glyph = '14px' ): string
    {
        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'alignItems' => 'center', 'justifyContent' => 'center' ],
            $this->b->icon( self::GLYPH, $this->t['onAccent'], $glyph ),
            array_merge( Blocks::gradient( $this->t['gradFrom'], $this->t['gradTo'] ), Blocks::border( '999px' ), Blocks::sizing( [ 'width' => $size . 'px', 'height' => $size . 'px' ] ) )
        );
    }

    /** The "view all" link, and with `$withLinks` also every sub-link of the item, so a panel of posts never hides them. */
    private function footer( array $item, bool $withLinks = false ): string
    {
        $all   = $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '8px', 'alignItems' => 'center' ],
            $this->b->link( self::VIEW_ALL . ' ' . $item['title'], $item['url'], [ 'size' => '14px', 'weight' => '700', 'color' => $this->t['accent'] ], $this->t['text'] )
            . $this->b->icon( self::GLYPH, $this->t['accent'], '12px' )
        );
        $links = $all;
        if ( $withLinks ) {
            foreach ( ColumnPlan::flatten( $item['children'] ) as $s ) {
                $links .= $this->b->link( $s['title'], $s['url'], [ 'size' => '14px', 'weight' => '500', 'color' => $this->t['muted'] ], $this->t['accent'], '', [], $s['newTab'] );
            }
        }

        return $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'row', 'flexWrap' => 'wrap', 'columnGap' => '24px', 'rowGap' => '6px', 'alignItems' => 'center' ], $links, array_merge( Blocks::padding( [ '14px', '0px', '0px', '0px' ] ), Blocks::topRule( $this->t['line'] ) ) );
    }

    /** The promo card beside the columns: the accent gradient, white text and a white pill button. */
    private function card( array $item ): string
    {
        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '12px', 'alignItems' => 'flex-start', 'justifyContent' => 'center' ],
            $this->cardBody( $item ),
            array_merge(
                Blocks::gradient( $this->t['gradFrom'], $this->t['gradTo'] ),
                Blocks::padding( [ '26px', '26px', '26px', '26px' ] ),
                Blocks::border( '18px' ),
                Blocks::shadow( '12px', '30px', $this->t['glow'], [ '18px', '38px', $this->t['glow'] ] ),
                Blocks::lift(),
                $this->enter(),
                Blocks::sizing( [ 'width' => '240px', 'maxWidth' => '100%' ] )
            )
        );
    }

    private function cardBody( array $item ): string
    {
        $text = '' !== $item['description'] ? $item['description'] : 'Everything under ' . $item['title'] . ' in one place.';

        return $this->b->heading( $item['title'], 'h3', [ 'size' => '24px', 'weight' => '700', 'color' => $this->t['onAccent'], 'lineHeight' => '1.2em' ] )
            . $this->b->text( '<p>' . self::esc( $text ) . '</p>', [ 'size' => '14px', 'color' => $this->t['onAccent'], 'lineHeight' => '1.5em' ] )
            . $this->b->button(
                self::VIEW_ALL,
                $item['url'],
                [ 'size' => '14px', 'weight' => '600' ],
                Blocks::padding( [ '10px', '22px', '10px', '22px' ] ),
                [ 'fill' => $this->t['onAccent'], 'label' => $this->t['gradFrom'], 'hoverFill' => $this->t['onAccentHover'], 'hoverLabel' => $this->t['gradFrom'], 'radius' => '999px' ]
            );
    }

    private function banner( array $item, string $image ): string
    {
        $pic = '' !== $image ? $this->b->image( $image, '', null, array_merge( Blocks::border( '14px' ), Blocks::sizing( [ 'width' => '100%' ] ) ) ) : '';

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'row', 'columnGap' => '26px', 'alignItems' => 'center' ],
            ( '' !== $pic ? $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column' ], $pic, Blocks::sizing( [ 'width' => '230px', 'maxWidth' => '100%' ] ) ) : '' )
                . $this->b->group( [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '10px', 'alignItems' => 'flex-start' ], $this->cardBody( $item ) ),
            array_merge(
                Blocks::gradient( $this->t['gradFrom'], $this->t['gradTo'] ),
                Blocks::padding( [ '20px', '20px', '20px', '20px' ] ),
                Blocks::border( '18px' ),
                Blocks::shadow( '12px', '30px', $this->t['glow'], [ '18px', '38px', $this->t['glow'] ] ),
                Blocks::lift(),
                $this->enter()
            )
        );
    }

    /** A post as a card: the picture inside a padded surface, the title and the date under it. */
    private function postCard( array $p ): string
    {
        $pic  = '' !== (string) ( $p['image'] ?? '' ) ? $this->b->image( (string) $p['image'], '', null, array_merge( Blocks::border( '10px' ), Blocks::sizing( [ 'width' => '100%' ] ) ) ) : '';
        $body = $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '4px' ],
            $this->b->link( (string) $p['title'], (string) $p['url'], [ 'size' => '15px', 'weight' => '600', 'color' => $this->t['text'] ], $this->t['accent'] )
            . ( '' !== (string) ( $p['date'] ?? '' ) ? $this->b->text( '<p>' . self::esc( (string) $p['date'] ) . '</p>', [ 'size' => '13px', 'color' => $this->t['muted'] ] ) : '' ),
            Blocks::padding( [ '2px', '6px', '6px', '6px' ] )
        );

        return $this->b->group(
            [ 'display' => 'flex', 'flexDirection' => 'column', 'rowGap' => '8px' ],
            $pic . $body,
            array_merge( $this->surfaceCard( [ '10px', '10px', '10px', '10px' ] ), Blocks::sizing( [ 'size' => [ 'flexGrow' ], 'width' => '210px', 'maxWidth' => '100%' ] ) )
        );
    }

    private static function esc( string $s ): string
    {
        return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}
