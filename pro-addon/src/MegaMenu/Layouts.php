<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The 20 mega menu layouts: five families times four variants, as data. `PanelBuilder` turns a definition and a menu item
 * into a Dropdown panel, `PreviewSvg` draws the same definition as a wireframe, so the two cannot drift apart.
 *
 * Keys of a definition:
 *   id, family, name, blurb      identity and the one-line description shown in the admin
 *   tone                         'light' | 'dark' (panel colours, see Palette)
 *   mode                         'auto' (panel as wide as `width`), 'row' (follows the row) or 'viewport' (full width, content centred at `width`)
 *   width                        content width in px
 *   columns                      number of link columns / grid columns
 *   rows                         list/icon style: 'plain' | 'desc' | 'icons' | 'cards'
 *   feature                      'none' | 'right' | 'left' | 'top' | 'cta' (the promo card, or a bar at the bottom)
 *   footer                       true: a footer row with a "view all" link
 *   posts                        number of latest posts to show (0 = none)
 *   postStyle                    'cards' | 'withlinks' | 'featured' | 'compact'
 */
final class Layouts
{
    public const FAMILIES = [
        'list'    => 'Simple lists',
        'columns' => 'Link columns',
        'feature' => 'Columns with a feature',
        'icons'   => 'Icons and descriptions',
        'latest'  => 'Latest posts',
    ];

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $d = static fn ( string $id, string $family, string $name, string $blurb, array $o ): array => array_merge(
            [ 'id' => $id, 'family' => $family, 'name' => $name, 'blurb' => $blurb, 'tone' => 'light', 'mode' => 'auto', 'width' => 560, 'columns' => 1, 'rows' => 'plain', 'feature' => 'none', 'footer' => false, 'posts' => 0, 'postStyle' => 'cards' ],
            $o
        );

        return [
            // Simple lists
            $d( 'list-plain', 'list', 'Plain list', 'A narrow list of links under the item.', [ 'width' => 260 ] ),
            $d( 'list-descriptions', 'list', 'List with descriptions', 'Each link has a one-line description under it.', [ 'width' => 340, 'rows' => 'desc' ] ),
            $d( 'list-icons', 'list', 'List with icons', 'A small icon in front of every link.', [ 'width' => 300, 'rows' => 'icons' ] ),
            $d( 'list-two-columns', 'list', 'Two-column list', 'The links in two columns.', [ 'width' => 480, 'columns' => 2 ] ),
            // Link columns
            $d( 'columns-3', 'columns', 'Three columns', 'Headed stacks of links side by side.', [ 'width' => 660, 'columns' => 3 ] ),
            $d( 'columns-4-wide', 'columns', 'Four columns, full width', 'A panel across the whole window with four link columns.', [ 'mode' => 'viewport', 'width' => 1120, 'columns' => 4 ] ),
            $d( 'columns-dark', 'columns', 'Three columns, dark', 'Three link columns on a dark, full-width panel.', [ 'tone' => 'dark', 'mode' => 'viewport', 'width' => 960, 'columns' => 3 ] ),
            $d( 'columns-footer', 'columns', 'Columns with a footer link', 'Three columns and a "view all" link at the bottom.', [ 'width' => 660, 'columns' => 3, 'footer' => true ] ),
            // Columns with a feature
            $d( 'feature-right', 'feature', 'Columns and a card on the right', 'Two link columns and a promo card.', [ 'width' => 760, 'columns' => 2, 'feature' => 'right' ] ),
            $d( 'feature-left', 'feature', 'Columns and a card on the left', 'A promo card, then two link columns.', [ 'width' => 760, 'columns' => 2, 'feature' => 'left' ] ),
            $d( 'feature-banner', 'feature', 'Banner across the top', 'A picture banner with a button above three link columns.', [ 'width' => 760, 'columns' => 3, 'feature' => 'top' ] ),
            $d( 'feature-cta', 'feature', 'Columns and a call-to-action bar', 'Three icon-link columns and a bar at the bottom. Like the divi5lab menu.', [ 'width' => 720, 'columns' => 3, 'rows' => 'icons', 'feature' => 'cta' ] ),
            // Icons and descriptions
            $d( 'icons-cards', 'icons', 'Icon cards', 'A grid of cards with an icon, title and description, and a footer link. Like the divi5lab Plugins menu.', [ 'width' => 640, 'columns' => 2, 'rows' => 'cards', 'footer' => true ] ),
            $d( 'icons-links-3', 'icons', 'Three icon columns', 'Icon links in three columns.', [ 'width' => 720, 'columns' => 3, 'rows' => 'icons' ] ),
            $d( 'icons-2x2', 'icons', 'Icon and description', 'Two columns of icon, title and description.', [ 'width' => 600, 'columns' => 2, 'rows' => 'desc' ] ),
            $d( 'icons-dark', 'icons', 'Icon cards, dark', 'The icon card grid on a dark panel.', [ 'tone' => 'dark', 'width' => 640, 'columns' => 2, 'rows' => 'cards' ] ),
            // Latest posts
            $d( 'latest-cards', 'latest', 'Three latest posts', 'Three cards with the newest posts and a "view all" link.', [ 'width' => 760, 'posts' => 3, 'postStyle' => 'cards', 'footer' => true ] ),
            $d( 'latest-links-cards', 'latest', 'Links and two posts', 'The links on the left and the two newest posts on the right.', [ 'width' => 700, 'posts' => 2, 'postStyle' => 'withlinks' ] ),
            $d( 'latest-featured', 'latest', 'One big post and three small', 'The newest post large, the next three as a list.', [ 'width' => 760, 'posts' => 4, 'postStyle' => 'featured' ] ),
            $d( 'latest-compact', 'latest', 'Four compact posts', 'Four small posts, a thumbnail beside each title.', [ 'width' => 640, 'columns' => 2, 'posts' => 4, 'postStyle' => 'compact' ] ),
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
