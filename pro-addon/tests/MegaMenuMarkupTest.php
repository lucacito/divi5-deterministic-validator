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
        $walk = function ( array $items ) use ( &$walk, $texts, $layout ): void {
            foreach ( $items as $it ) {
                $this->assertContains( $it['title'] . '|' . $it['url'], $texts, 'link "' . $it['title'] . '" is in the nav for ' . $layout['id'] );
                $walk( $it['children'] );
            }
        };
        $walk( $tree );
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
}
