<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The 13 mega menu layouts in five families, as data. `PanelBuilder` turns a definition and a menu item
 * into a Dropdown panel, `PreviewSvg` draws the same definition as a wireframe, so the two cannot drift apart.
 *
 * Keys of a definition:
 *   id, family, name, blurb      identity and the one-line description shown in the admin
 *   tone                         'light' | 'dark' (panel colours, see Palette)
 *   mode                         'auto' (panel as wide as `width`), 'row' (follows the row) or 'viewport' (full width, content centred at `width`)
 *   width                        content width in px
 *   columns                      number of link columns / grid columns
 *   rows                         link style: 'plain' | 'cards' | 'hairline'
 *   feature                      'none' | 'right' | 'left' | 'top' (the promo card, or the banner above the columns)
 *   footer                       true: a footer row with a "view all" link
 *   posts                        number of latest posts to show (0 = none)
 *   postStyle                    'cards' | 'withlinks'
 *   intro                        true: a coloured intro panel (title, one line, a "view all" link) beside the links (showcase family)
 *   surface                      'open' (links straight on the panel) | 'cards' (every link column is an opaque tile)
 *   backdrop                     'none' (the panel's own colour) | 'accent' (a tinted translucent backdrop behind the tiles) | 'wash'
 *                                (a pale translucent backdrop); text never sits on the backdrop itself
 */
final class Layouts
{
    public const FAMILIES = [
        'columns' => 'Link columns',
        'feature' => 'Columns with a feature',
        'icons'   => 'Icons and descriptions',
        'latest'  => 'Latest posts',
        'showcase' => 'Showcase panels',
    ];

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $d = static fn ( string $id, string $family, string $name, string $blurb, array $o ): array => array_merge(
            [ 'id' => $id, 'family' => $family, 'name' => $name, 'blurb' => $blurb, 'tone' => 'light', 'mode' => 'auto', 'width' => 560, 'columns' => 1, 'rows' => 'plain', 'feature' => 'none', 'footer' => false, 'posts' => 0, 'postStyle' => 'cards', 'intro' => false, 'surface' => 'open', 'backdrop' => 'none' ],
            $o
        );

        return [
            // Link columns
            $d( 'columns-dark', 'columns', 'Three columns, dark', 'Three link columns on a dark, full-width panel.', [ 'tone' => 'dark', 'mode' => 'viewport', 'width' => 1080, 'columns' => 3 ] ),
            $d( 'columns-footer', 'columns', 'Columns with a footer link', 'Three columns and a "view all" link at the bottom.', [ 'width' => 660, 'columns' => 3, 'footer' => true ] ),
            // Columns with a feature
            $d( 'feature-right', 'feature', 'Columns and a card on the right', 'Two link columns and a promo card.', [ 'width' => 760, 'columns' => 2, 'feature' => 'right' ] ),
            $d( 'feature-left', 'feature', 'Columns and a card on the left', 'A promo card, then two link columns.', [ 'width' => 760, 'columns' => 2, 'feature' => 'left' ] ),
            $d( 'feature-banner', 'feature', 'Banner across the top', 'A picture banner with a button above three link columns.', [ 'width' => 760, 'columns' => 3, 'feature' => 'top' ] ),
            // Icons and descriptions
            $d( 'icons-cards', 'icons', 'Icon cards', 'A grid of cards with an icon, title and description, and a footer link. Like the divi5lab Plugins menu.', [ 'width' => 640, 'columns' => 2, 'rows' => 'cards', 'footer' => true ] ),
            $d( 'icons-dark', 'icons', 'Icon cards, dark', 'The icon card grid on a dark panel.', [ 'tone' => 'dark', 'width' => 640, 'columns' => 2, 'rows' => 'cards' ] ),
            // Latest posts
            $d( 'latest-cards', 'latest', 'Three latest posts', 'Three cards with the newest posts and a "view all" link.', [ 'width' => 760, 'posts' => 3, 'postStyle' => 'cards', 'footer' => true ] ),
            $d( 'latest-links-cards', 'latest', 'Links and two posts', 'The links on the left and the two newest posts on the right.', [ 'width' => 700, 'posts' => 2, 'postStyle' => 'withlinks' ] ),
            // Showcase panels
            $d( 'showcase-intro-cards', 'showcase', 'Intro and link cards', 'A coloured intro panel and white link cards on a tinted backdrop across the window.', [ 'mode' => 'viewport', 'width' => 1080, 'columns' => 3, 'rows' => 'hairline', 'intro' => true, 'surface' => 'cards', 'backdrop' => 'accent' ] ),
            $d( 'showcase-tiles', 'showcase', 'Link tiles', 'Four white tiles, each with an arrow heading and ruled links, on a pale backdrop.', [ 'mode' => 'viewport', 'width' => 1080, 'columns' => 4, 'rows' => 'hairline', 'surface' => 'cards', 'backdrop' => 'wash' ] ),
            $d( 'showcase-intro-links', 'showcase', 'Intro and ruled links', 'A coloured intro panel beside three columns of ruled links on a plain panel.', [ 'width' => 880, 'columns' => 3, 'rows' => 'hairline', 'intro' => true ] ),
            $d( 'showcase-tiles-dark', 'showcase', 'Dark link tiles', 'Dark tiles with ruled links on a dark backdrop across the window.', [ 'tone' => 'dark', 'mode' => 'viewport', 'width' => 1080, 'columns' => 3, 'rows' => 'hairline', 'surface' => 'cards', 'backdrop' => 'accent' ] ),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find( string $id ): ?array
    {
        foreach ( self::all() as $layout ) {
            if ( $layout['id'] === $id ) {
                return $layout;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map( static fn ( array $l ): string => $l['id'], self::all() );
    }

    /** Whether the layout shows the newest posts (so the service must fetch them). */
    public static function needsPosts( array $layout ): bool
    {
        return (int) $layout['posts'] > 0;
    }

    /** Whether the layout shows a picture from the built-in image pack. */
    public static function needsFeatureImage( array $layout ): bool
    {
        return 'top' === $layout['feature'];
    }
}
