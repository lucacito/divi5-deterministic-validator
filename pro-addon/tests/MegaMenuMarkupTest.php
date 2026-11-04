<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\Blocks;
use AiEditorDivi5\Pro\MegaMenu\HeaderEdit;
use AiEditorDivi5\WP\LayoutGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MegaMenuFixture.php';

/** Every layout, built for every menu shape, must be a layout the checker accepts and must keep every menu link. */
class MegaMenuMarkupTest extends TestCase
{
    /** @return iterable<string,array{0:array<string,mixed>,1:string}> */
    public static function cases(): iterable
    {
        foreach ( MegaMenuFixture::layouts() as $layout ) {
            foreach ( array_keys( MegaMenuFixture::menus() ) as $menu ) {
                yield $layout['id'] . ' / ' . $menu => [ $layout, $menu ];
            }
        }
    }

    #[DataProvider( 'cases' )]
    public function testTheHeaderWithTheWrapperPassesTheLayoutGate( array $layout, string $menu ): void
    {
        $tree    = MegaMenuFixture::menus()[ $menu ];
        $content = HeaderEdit::apply( MegaMenuFixture::header(), MegaMenuFixture::wrapper( $layout, $tree ) );
        $verdict = LayoutGate::check( $content, MegaMenuFixture::header() );
        $this->assertTrue( $verdict->isValid(), json_encode( array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() ) ) );
    }

    #[DataProvider( 'cases' )]
    public function testEveryDropdownHasItsSettingsWrappedInDesktopValue( array $layout, string $menu ): void
    {
        // A raw `module.advanced.dropdown = {showOn...}` is a PHP fatal on the real Divi (a 500 for the whole page).
        $markup = MegaMenuFixture::wrapper( $layout, MegaMenuFixture::menus()[ $menu ] );
        preg_match_all( '/<!-- wp:divi\/dropdown (\{.*?\}) -->/s', $markup, $all );
        $this->assertNotEmpty( $all[1], 'at least one item has a panel' );
        foreach ( $all[1] as $json ) {
            $attrs = json_decode( $json, true );
            $this->assertIsArray( $attrs );
            $settings = $attrs['module']['advanced']['dropdown']['desktop']['value'] ?? null;
            $this->assertIsArray( $settings, 'dropdown settings sit in desktop.value' );
            $this->assertSame( 'hover', $settings['showOn'] );
            $this->assertSame( 'click', $attrs['module']['advanced']['dropdown']['tablet']['value']['showOn'] );
            $this->assertSame( 'click', $attrs['module']['advanced']['dropdown']['phone']['value']['showOn'] );
            $this->assertArrayNotHasKey( 'showOn', $attrs['module']['advanced']['dropdown'], 'no raw settings beside the breakpoints' );
        }
    }

    #[DataProvider( 'cases' )]
    public function testNoMenuLinkIsDropped( array $layout, string $menu ): void
    {
        $tree   = MegaMenuFixture::menus()[ $menu ];
        $markup = MegaMenuFixture::wrapper( $layout, $tree );
        $texts  = [];
        preg_match_all( '/<!-- wp:divi\/link (\{.*?\})\s*\/?-->/s', $markup, $all );
        foreach ( $all[1] as $json ) {
            $a = json_decode( $json, true );
            if ( is_array( $a ) ) {
                $texts[] = $a['content']['innerContent']['desktop']['value']['text'] . '|' . $a['content']['innerContent']['desktop']['value']['linkUrl'];
            }
        }
        // A second-level item that has a third level is a label (a Text module), not a link; its title must still be on the panel.
        $labels = [];
        preg_match_all( '/<!-- wp:divi\/text (\{.*?\})\s*\/?-->/s', $markup, $texts2 );
        foreach ( $texts2[1] as $json ) {
            $a = json_decode( $json, true );
            if ( is_array( $a ) && is_string( $a['content']['innerContent']['desktop']['value'] ?? null ) ) {
                $labels[] = html_entity_decode( strip_tags( $a['content']['innerContent']['desktop']['value'] ), ENT_QUOTES | ENT_HTML5 );
            }
        }
        $walk = function ( array $items, int $depth ) use ( &$walk, $texts, $labels, $layout ): void {
            foreach ( $items as $it ) {
                // The "latest posts" cards layout lists every sub-link flat in its footer (no groups), so there they stay links.
                $flat = (int) $layout['posts'] > 0 && 'cards' === $layout['postStyle'];
                if ( 1 === $depth && [] !== $it['children'] && ! $flat ) {
                    $this->assertContains( $it['title'], $labels, 'label "' . $it['title'] . '" is on the panel for ' . $layout['id'] );
                    $this->assertNotContains( $it['title'] . '|' . $it['url'], $texts, 'a label is not a link: "' . $it['title'] . '" in ' . $layout['id'] );
                } else {
                    $this->assertContains( $it['title'] . '|' . $it['url'], $texts, 'link "' . $it['title'] . '" is in the nav for ' . $layout['id'] );
                }
                $walk( $it['children'], $depth + 1 );
            }
        };
        $walk( $tree, 0 );
    }

    #[DataProvider( 'cases' )]
    public function testTheMarkupIsDeterministicAndTheWrapperIsLabelled( array $layout, string $menu ): void
    {
        $tree = MegaMenuFixture::menus()[ $menu ];
        $a    = MegaMenuFixture::wrapper( $layout, $tree );
        $this->assertSame( $a, MegaMenuFixture::wrapper( $layout, $tree ) );
        $this->assertStringContainsString( '"adminLabel":{"desktop":{"value":"' . Blocks::ADMIN_LABEL . '"}}', $a );
        $this->assertStringContainsString( '"disabledOn":{"tablet":{"value":"on"},"phone":{"value":"on"}}', $a );
        $this->assertSame( 1, substr_count( $a, '"value":"' . Blocks::ADMIN_LABEL . '"' ), 'only the wrapper carries the wrapper label' );
        // Every panel says which menu item and which layout it was built from; HeaderEdit::panels reads that back.
        $panels = HeaderEdit::panels( $a );
        $this->assertCount( substr_count( $a, '<!-- wp:divi/dropdown ' ), $panels );
        $this->assertSame( [ $layout['id'] ], array_values( array_unique( $panels ) ) );
        // Balanced block comments: the depth never goes below zero and ends at zero.
        preg_match_all( '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+\{.*?\})?\s*(\/?)-->/s', $a, $comments, PREG_SET_ORDER );
        $depth = 0;
        foreach ( $comments as $c ) {
            $depth += '/' === $c[1] ? -1 : ( '/' === $c[3] ? 0 : 1 );
            $this->assertGreaterThanOrEqual( 0, $depth );
        }
        $this->assertSame( 0, $depth );
        // No raw `--`, `<` or `>` inside any attribute JSON (it would end the comment early).
        preg_match_all( '/<!-- wp:divi\/[a-z0-9-]+ (\{.*?\})\s*\/?-->/s', $a, $all );
        foreach ( $all[1] as $json ) {
            $this->assertStringNotContainsString( '--', $json );
            $this->assertStringNotContainsString( '<', $json );
            $this->assertNotNull( json_decode( $json, true ), 'valid JSON: ' . substr( $json, 0, 80 ) );
        }
    }

    public function testTheAttributePathsAreTheProvenOnes(): void
    {
        $proven = array_filter( array_map( 'trim', file( __DIR__ . '/fixtures/megamenu-proven-paths.txt' ) ?: [] ), static fn ( string $l ): bool => '' !== $l && '#' !== $l[0] );
        $proven = array_map( static fn ( string $l ): string => trim( explode( '  ', $l )[0] ), $proven );
        $used   = [];
        foreach ( MegaMenuFixture::layouts() as $layout ) {
            foreach ( MegaMenuFixture::menus() as $tree ) {
                $used = array_merge( $used, MegaMenuFixture::paths( MegaMenuFixture::wrapper( $layout, $tree ) ) );
            }
        }
        $used    = array_values( array_unique( $used ) );
        $unknown = array_values( array_diff( $used, $proven ) );
        $this->assertSame( [], $unknown, "Attribute paths used by the mega menu but not in fixtures/megamenu-proven-paths.txt (add a path there only with live render evidence):\n" . implode( "\n", $unknown ) );
        $this->assertSame( [], array_values( array_diff( $proven, $used ) ), 'the proven list holds only paths the layouts really use' );
    }

    // ---- the owner's own content --------------------------------------------------------------------------------------

    /** Every block of one name in a wrapper, as decoded attribute arrays. @return list<array<string,mixed>> */
    private static function blocksOf( string $markup, string $name ): array
    {
        preg_match_all( '/<!-- wp:divi\/' . $name . ' (\{.*?\})\s*\/?-->/s', $markup, $all );

        return array_values( array_filter( array_map( static fn ( string $j ) => json_decode( $j, true ), $all[1] ), 'is_array' ) );
    }

    public static function contentLayouts(): array
    {
        $out = [];
        foreach ( MegaMenuFixture::layouts() as $l ) {
            if ( ! empty( $l['content'] ) ) {
                $out[ $l['id'] ] = [ $l ];
            }
        }

        return $out;
    }

    public function testExactlyTheLayoutsWithACardBannerOrIntroTakeContent(): void
    {
        $this->assertSame( [ 'feature-right', 'feature-left', 'feature-banner', 'showcase-intro-cards', 'showcase-intro-links' ], array_keys( self::contentLayouts() ) );
    }

    #[DataProvider( 'contentLayouts' )]
    public function testTheOwnersHeadlineTextButtonAndPictureReplaceTheDefaults( array $layout ): void
    {
        $tree   = MegaMenuFixture::menus()['grouped'];
        $own    = MegaMenuFixture::ownContent( $tree );
        $markup = MegaMenuFixture::wrapper( $layout, $tree, '5.14.0', $own );
        $this->assertNotSame( MegaMenuFixture::wrapper( $layout, $tree ), $markup );

        $headings = array_map( static fn ( array $a ) => $a['title']['innerContent']['desktop']['value'] ?? null, self::blocksOf( $markup, 'heading' ) );
        $this->assertContains( 'Own headline 2', $headings, 'the headline is the owner\'s' );
        $texts = array_map( static fn ( array $a ) => (string) ( $a['content']['innerContent']['desktop']['value'] ?? '' ), self::blocksOf( $markup, 'text' ) );
        $this->assertContains( '<p>Own text -- with a &lt; sign &amp; &quot;quotes&quot;</p>', $texts, 'the text is the owner\'s, escaped' );
        // The button (card, banner) or the "view all" link (intro panel) carries the owner's label and address.
        $buttons = array_map( static fn ( array $a ) => $a['button']['innerContent']['desktop']['value'] ?? [], self::blocksOf( $markup, 'button' ) );
        $links   = array_map( static fn ( array $a ) => $a['content']['innerContent']['desktop']['value'] ?? [], self::blocksOf( $markup, 'link' ) );
        $labels  = array_merge( array_column( $buttons, 'text' ), array_column( $links, 'text' ) );
        $this->assertContains( 'Own button', $labels );
        $urls = array_merge( array_column( $buttons, 'linkUrl' ), array_column( $links, 'linkUrl' ) );
        $this->assertContains( 'https://example.test/own-2/', $urls );
        // The picture is an Image module with the Media Library's own alt text.
        $images = array_map( static fn ( array $a ) => $a['image']['innerContent']['desktop']['value'] ?? [], self::blocksOf( $markup, 'image' ) );
        $mine   = array_values( array_filter( $images, static fn ( array $v ): bool => 'https://example.test/wp-content/uploads/own-2.jpg' === ( $v['src'] ?? '' ) ) );
        $this->assertCount( 1, $mine, 'the picture of the item is on its panel once' );
        $this->assertSame( 'A "quoted" <b>alt</b> & more -- text', $mine[0]['alt'] );
        // Still valid block comments: nothing in the JSON can end the comment early.
        preg_match_all( '/<!-- wp:divi\/[a-z0-9-]+ (\{.*?\})\s*\/?-->/s', $markup, $all );
        foreach ( $all[1] as $json ) {
            $this->assertStringNotContainsString( '--', $json );
            $this->assertStringNotContainsString( '<', $json );
            $this->assertNotNull( json_decode( $json, true ) );
        }
    }

    #[DataProvider( 'contentLayouts' )]
    public function testOwnContentUsesOnlyProvenPaths( array $layout ): void
    {
        $proven = array_filter( array_map( 'trim', file( __DIR__ . '/fixtures/megamenu-proven-paths.txt' ) ?: [] ), static fn ( string $l ): bool => '' !== $l && '#' !== $l[0] );
        $proven = array_map( static fn ( string $l ): string => trim( explode( '  ', $l )[0] ), $proven );
        $tree   = MegaMenuFixture::menus()['grouped'];
        $used   = MegaMenuFixture::paths( MegaMenuFixture::wrapper( $layout, $tree, '5.14.0', MegaMenuFixture::ownContent( $tree ) ) );
        $this->assertSame( [], array_values( array_diff( $used, $proven ) ) );
    }

    public function testALayoutWithoutAContentSlotIgnoresOwnContent(): void
    {
        $tree = MegaMenuFixture::menus()['grouped'];
        foreach ( MegaMenuFixture::layouts() as $layout ) {
            if ( empty( $layout['content'] ) ) {
                $this->assertSame( MegaMenuFixture::wrapper( $layout, $tree ), MegaMenuFixture::wrapper( $layout, $tree, '5.14.0', MegaMenuFixture::ownContent( $tree ) ), $layout['id'] );
            }
        }
    }

    public function testEmptyOrMissingContentChangesNothing(): void
    {
        $tree = MegaMenuFixture::menus()['grouped'];
        foreach ( self::contentLayouts() as [ $layout ] ) {
            $empty = [ 2 => [ 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'image' => null ] ];
            $this->assertSame( MegaMenuFixture::wrapper( $layout, $tree ), MegaMenuFixture::wrapper( $layout, $tree, '5.14.0', $empty ), $layout['id'] );
        }
    }

    public function testThePlaceholderOfTheBannerGivesWayToTheOwnersPicture(): void
    {
        $tree   = MegaMenuFixture::menus()['grouped'];
        $banner = \AiEditorDivi5\Pro\MegaMenu\Layouts::find( 'feature-banner' );
        $with   = MegaMenuFixture::wrapper( $banner, $tree, '5.14.0', MegaMenuFixture::ownContent( $tree ) );
        $this->assertStringNotContainsString( 'card-blue-1.svg', $with );
        $this->assertStringContainsString( 'card-blue-1.svg', MegaMenuFixture::wrapper( $banner, $tree ) );
    }
}
