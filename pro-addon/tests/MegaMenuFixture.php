<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\Blocks;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MenuTree;
use AiEditorDivi5\Pro\MegaMenu\NavBuilder;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Shared fixtures for the mega menu tests: menus of different shapes and a header laid out like the real Theme Builder
 * header (section > row > column > divi/menu with the logo inside the Menu module).
 */
final class MegaMenuFixture
{
    /** A flat WordPress menu item. */
    private static function item( int $id, int $parent, string $title, string $url, string $description = '', string $type = 'custom', string $object = '', int $objectId = 0 ): array
    {
        return [ 'id' => $id, 'parent' => $parent, 'order' => $id, 'title' => $title, 'url' => $url, 'newTab' => false, 'description' => $description, 'type' => $type, 'object' => $object, 'objectId' => $objectId ];
    }

    /** @return array<string,list<array<string,mixed>>> name => MenuTree::build() result */
    public static function menus(): array
    {
        $grouped = [
            self::item( 1, 0, 'Home', 'https://example.test/' ),
            self::item( 2, 0, 'Services', 'https://example.test/services/', 'What we do for you' ),
            self::item( 3, 2, 'Design', 'https://example.test/design/' ),
            self::item( 4, 3, 'Branding', 'https://example.test/branding/', 'Logos and identity' ),
            self::item( 5, 3, 'Web design', 'https://example.test/web-design/' ),
            self::item( 6, 2, 'Build', 'https://example.test/build/' ),
            self::item( 7, 6, 'Websites', 'https://example.test/websites/' ),
            self::item( 8, 6, 'Online shops', 'https://example.test/shops/', 'Sell online & more' ),
            self::item( 9, 6, 'Landing pages', 'https://example.test/landing/' ),
            self::item( 10, 2, 'Grow', 'https://example.test/grow/' ),
            self::item( 11, 10, 'SEO', 'https://example.test/seo/' ),
            self::item( 12, 10, 'Ads', 'https://example.test/ads/' ),
            self::item( 13, 2, 'Care plans', 'https://example.test/care/' ),
            self::item( 14, 0, 'About', 'https://example.test/about/' ),
        ];
        $flat = [
            self::item( 1, 0, 'Home', 'https://example.test/' ),
            self::item( 2, 0, 'Blog', 'https://example.test/blog/', '', 'taxonomy', 'category', 7 ),
            self::item( 3, 2, 'News', 'https://example.test/news/' ),
            self::item( 4, 2, 'Guides', 'https://example.test/guides/', 'How-to articles' ),
            self::item( 5, 2, 'Case studies', 'https://example.test/cases/' ),
            self::item( 6, 2, 'Podcast', 'https://example.test/podcast/' ),
            self::item( 7, 2, 'Events', 'https://example.test/events/' ),
            self::item( 8, 0, 'Contact', 'https://example.test/contact/' ),
        ];
        $deep = [
            self::item( 1, 0, 'Products', 'https://example.test/products/' ),
            self::item( 2, 1, 'Software', 'https://example.test/software/' ),
            self::item( 3, 2, 'Editor', 'https://example.test/editor/' ),
            self::item( 4, 3, 'Plugins for the editor', 'https://example.test/editor/plugins/' ),
            self::item( 5, 1, 'Hardware', 'https://example.test/hardware/' ),
            self::item( 6, 5, 'Keyboards', 'https://example.test/keyboards/' ),
            self::item( 7, 5, 'Mice', 'https://example.test/mice/' ),
            self::item( 8, 5, 'Screens', 'https://example.test/screens/' ),
            self::item( 9, 5, 'Cables', 'https://example.test/cables/' ),
            self::item( 10, 5, 'Stands', 'https://example.test/stands/' ),
            self::item( 11, 5, 'Bags', 'https://example.test/bags/' ),
        ];
        // Awkward text: quotes, an ampersand, angle brackets, a dash run and non-Latin text must survive in block comments and HTML.
        $nasty = [
            self::item( 1, 0, 'Q & A "quoted" <b>x</b> -- end', 'https://example.test/qa/?a=1&b=2', 'Say "hi" & <wave> -- ok' ),
            self::item( 2, 1, 'Café — crème', 'https://example.test/cafe/', 'Ünïcode · 日本語' ),
            self::item( 3, 1, 'Second', 'https://example.test/second/' ),
        ];

        return [
            'grouped' => MenuTree::build( $grouped ),
            'flat'    => MenuTree::build( $flat ),
            'deep'    => MenuTree::build( $deep ),
            'nasty'   => MenuTree::build( $nasty ),
        ];
    }

    /** Latest posts for the "latest" layouts. @return list<array{title:string,url:string,image:string,date:string}> */
    public static function posts(): array
    {
        return [
            [ 'title' => 'First post', 'url' => 'https://example.test/first/', 'image' => 'https://example.test/a.jpg', 'date' => 'October 3, 2026' ],
            [ 'title' => 'Second post', 'url' => 'https://example.test/second-post/', 'image' => '', 'date' => 'October 2, 2026' ],
            [ 'title' => 'Third "quoted" post', 'url' => 'https://example.test/third/', 'image' => 'https://example.test/c.jpg', 'date' => 'October 1, 2026' ],
            [ 'title' => 'Fourth post', 'url' => 'https://example.test/fourth/', 'image' => 'https://example.test/d.jpg', 'date' => 'September 30, 2026' ],
        ];
    }

    public static function look(): array
    {
        return [
            'font'       => [ 'size' => '1rem', 'weight' => '600', 'color' => '#4e6a6d' ],
            'hoverColor' => '#0b6e4f',
            'logo'       => [ 'src' => 'https://example.test/logo.png', 'id' => '12', 'alt' => 'Example', 'titleText' => 'logo', 'width' => '256', 'height' => '256', 'linkUrl' => '/' ],
            'logoWidth'  => '230px',
            'menuId'     => '35',
        ];
    }

    /** The Menu module block exactly as a header export writes it (logo inside, an empty `{}`, a global colour token). */
    public static function menuBlock(): string
    {
        return '<!-- wp:divi/menu {"module":{"decoration":{"attributes":{},"background":{"desktop":{"value":{"color":"#ffffff"}}}}},"logo":{"innerContent":{"desktop":{"value":{"src":"https://example.test/logo.png","id":"12","alt":"Example","titleText":"logo","width":"256","height":"256","linkUrl":"/"}}},"decoration":{"sizing":{"desktop":{"value":{"width":"230px"}}}}},"menu":{"advanced":{"menuId":{"desktop":{"value":"35"}}},"decoration":{"font":{"font":{"desktop":{"value":{"size":"1rem","weight":"600","color":"#4e6a6d"},"hover":{"color":"#0b6e4f"}}}}}},"menuDropdown":{},"builderVersion":"5.14.0"} /-->';
    }

    /** A header like the real one: one section holding the Menu module, another with a search module. */
    public static function header( ?string $menu = null ): string
    {
        $menu ??= self::menuBlock();

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . $menu
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
            . "\n"
            . '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/search {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** The wrapper for one layout and one menu. */
    public static function wrapper( array $layout, array $tree, string $version = '5.14.0' ): string
    {
        return ( new NavBuilder( new Blocks( $version ) ) )->build(
            $layout,
            $tree,
            [ 'look' => self::look(), 'posts' => self::postsFor( $tree ), 'featureImage' => 'https://example.test/wp-content/plugins/free/assets/images/card-blue-1.svg' ]
        );
    }

    /** @return array<int,list<array{title:string,url:string,image:string,date:string}>> */
    public static function postsFor( array $tree ): array
    {
        $out = [];
        foreach ( $tree as $item ) {
            if ( [] !== $item['children'] ) {
                $out[ $item['id'] ] = self::posts();
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function layouts(): array
    {
        return Layouts::all();
    }

    /**
     * Every attribute path a block comment carries, as `divi/name:dotted.path` (values ignored, a breakpoint key is
     * written as `{bp}`), sorted and unique.
     *
     * @return list<string>
     */
    public static function paths( string $markup ): array
    {
        $paths = [];
        preg_match_all( '/<!--\s+wp:(divi\/[a-z0-9-]+)\s+(\{.*?\})\s*\/?-->/s', $markup, $all, PREG_SET_ORDER );
        foreach ( $all as $m ) {
            $attrs = json_decode( $m[2], true );
            if ( is_array( $attrs ) ) {
                self::walk( $attrs, '', $m[1], $paths );
            }
        }
        $paths = array_values( array_unique( $paths ) );
        sort( $paths );

        return $paths;
    }

    private static function walk( array $node, string $prefix, string $block, array &$out ): void
    {
        foreach ( $node as $key => $value ) {
            if ( 'builderVersion' === $key && '' === $prefix ) {
                continue;
            }
            $k    = in_array( $key, [ 'desktop', 'tablet', 'phone' ], true ) ? '{bp}' : (string) $key;
            $path = '' === $prefix ? $k : $prefix . '.' . $k;
            if ( is_array( $value ) && [] !== $value && ! array_is_list( $value ) ) {
                self::walk( $value, $path, $block, $out );
            } else {
                $out[] = $block . ':' . $path;
            }
        }
    }
}
