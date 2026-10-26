<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Bands;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Tests\Support\Dogfood\Page;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/Support/Dogfood/Page.php';

/**
 * Design switch, Task 2: the engine's band rule is a PORT of the dev scorer's (`Page::bandOf`, `Page::colourKey`), and the
 * two independent implementations must agree on every block of the recipe library and of the dogfood corpus.
 */
class BandsTest extends TestCase
{
    private const CORPUS = __DIR__ . '/../../fixtures/dogfood';

    public function testBandsEqualTheScorersOnEveryLibraryAndCorpusBlock(): void
    {
        $pages = [];
        foreach ( DesignLanguages::ids() as $id ) {
            foreach ( SectionRecipes::names() as $name ) {
                $markup = SectionRecipes::recipe( $name, $id );
                $this->assertIsString( $markup, $name . ' under ' . $id );
                $pages[ $name . ' @ ' . $id ] = [ $markup, $id ];
            }
        }
        $this->assertCount( 710, $pages );
        $corpus = json_decode( (string) file_get_contents( self::CORPUS . '/corpus.json' ), true );
        $this->assertCount( 8, $corpus );
        foreach ( $corpus as $entry ) {
            $pages[ 'corpus ' . $entry['name'] ] = [ (string) file_get_contents( self::CORPUS . '/' . $entry['page'] ), $entry['language'] ];
        }

        $blocks = 0;
        $seen   = [];
        foreach ( $pages as $label => [ $markup, $languageId ] ) {
            $language = DesignLanguages::get( $languageId );
            $this->assertIsArray( $language, $label );
            $theirs = Page::parse( $markup )->blocks( $language );
            $ours   = self::asScorerSees( $markup, PageScan::scan( $markup, $language ) );
            $this->assertTrue( PageScan::balanced( $markup ), $label );
            $this->assertCount( count( $theirs ), $ours, $label . ': block count' );
            foreach ( $theirs as $i => $t ) {
                $o = $ours[ $i ];
                $this->assertSame( $t['name'], $o['name'], $label . ' block ' . $i );
                $this->assertSame( $t['band'], $o['band'], $label . ' block ' . $i . ' ' . $t['key'] );
                $this->assertSame( $t['attrs'], null === $o['json'] ? [] : json_decode( $o['json'], true ), $label . ' block ' . $i . ': the span holds the attributes' );
                $seen[ $o['band'] ] = true;
                ++$blocks;
            }
        }
        $this->assertGreaterThan( 10000, $blocks );
        ksort( $seen );
        $this->assertSame( [ 'accent', 'dark', 'light', 'panel', 'surface', 'unknown' ], array_keys( $seen ), 'every band occurs, so the comparison is not vacuous' );
    }

    public function testOfEqualsTheScorersBandOfOnEveryLibraryBlock(): void
    {
        $checked = 0;
        foreach ( DesignLanguages::ids() as $id ) {
            $language = DesignLanguages::get( $id );
            foreach ( SectionRecipes::names() as $name ) {
                foreach ( Page::parse( (string) SectionRecipes::recipe( $name, $id ) )->blocks( $language ) as $b ) {
                    $this->assertSame( Page::bandOf( $b['attrs'], $language ), Bands::of( $b['attrs'], $language ), $name . ' ' . $b['key'] . ' @ ' . $id );
                    ++$checked;
                }
            }
        }
        $this->assertGreaterThan( 10000, $checked );
    }

    public function testColourKeyMatchesThePagesOne(): void
    {
        $values = [
            '#FFF', '#fff', '#ffffff', '#FFFFFF', ' #ffffff ', 'rgb(255 255 255)', 'rgb(255,255,255)', 'rgba(255, 255, 255, 1)',
            'rgba(0,0,0,0.7)', 'rgba(0, 0, 0, .7)', 'rgb(0 0 0 / 0.5)', 'RGBA(1,2,3,0.333)', 'transparent', 'TRANSPARENT',
            '#ffffff80', '#fff8', '#00000000', 'rgba(0,0,0,0)', 'rgba(300,0,0,1)', 'rgba(0,0,0,2)', '#ggg', 'red', '', 'junk',
            '#12345', 'rgb(1,2)', '{{aied:color:bg}}', '$variable({"type":"color","value":{"name":"gcid-x"}})$',
        ];
        foreach ( $values as $v ) {
            $this->assertSame( Page::colourKey( $v ), Bands::colourKey( $v ), var_export( $v, true ) );
            $this->assertSame( Page::isClear( $v ), Bands::isClear( $v ), var_export( $v, true ) );
        }
        $this->assertSame( '255,255,255,1', Bands::colourKey( '#FFF' ) );
        $this->assertSame( Bands::colourKey( '#FFF' ), Bands::colourKey( 'rgb(255 255 255)' ) );
        $this->assertSame( '0,0,0,0.7', Bands::colourKey( 'rgba(0,0,0,0.7)' ) );
        $this->assertSame( '0,0,0,0', Bands::colourKey( 'transparent' ) );
        $this->assertSame( '255,255,255,0.5', Bands::colourKey( '#ffffff80' ) );
        $this->assertNull( Bands::colourKey( 'junk' ) );
        foreach ( [ null, 0, [], true ] as $notAString ) {
            $this->assertSame( Page::isClear( $notAString ), Bands::isClear( $notAString ) );
        }
    }

    public function testThePaintRules(): void
    {
        $lang = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $lang );
        $c   = $lang['colors'];
        $bg  = static fn ( array $value ): array => [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => $value ] ] ] ] ];
        $cases = [
            [ [], null ],
            [ $bg( [ 'color' => '' ] ), null ],
            [ $bg( [ 'color' => 'transparent' ] ), null ],
            [ $bg( [ 'color' => $c['bg'] ] ), 'light' ],
            [ $bg( [ 'color' => strtoupper( $c['bg'] ) ] ), 'light' ],
            [ $bg( [ 'color' => $c['surface'] ] ), 'surface' ],
            [ $bg( [ 'color' => $c['accent'] ] ), 'accent' ],
            [ $bg( [ 'color' => $c['text'] ] ), 'panel' ],
            [ $bg( [ 'color' => $c['overlay'] ] ), 'dark' ],
            [ $bg( [ 'color' => 'rgb(1 2 3)' ] ), 'unknown' ],
            [ $bg( [ 'color' => 'junk' ] ), 'unknown' ],
            [ $bg( [ 'image' => [ 'url' => 'x.svg' ] ] ), 'dark' ],
            [ $bg( [ 'gradient' => [ 'enabled' => 'on', 'stops' => [ [ 'color' => $c['overlay'] ] ] ] ] ), 'dark' ],
            [ $bg( [ 'gradient' => [ 'enabled' => 'on', 'stops' => [ [ 'color' => $c['bg'] ] ] ] ] ), 'unknown' ],
            [ $bg( [ 'gradient' => [ 'enabled' => 'off' ], 'color' => $c['accent'] ] ), 'accent' ],
        ];
        foreach ( $cases as $i => [ $attrs, $band ] ) {
            $this->assertSame( $band, Bands::of( $attrs, $lang ), 'case ' . $i );
            $this->assertSame( Page::bandOf( $attrs, $lang ), Bands::of( $attrs, $lang ), 'case ' . $i );
        }
    }

    /**
     * The scan as the scorer's `Page` lists it: `Page::parse()` leaves out a top-level placeholder only when the trimmed page
     * starts with one (otherwise it wraps the page in its own).
     *
     * @param list<array{name:string, json:?string, offset:int, length:int, band:string, parent:?int}> $scan
     *
     * @return list<array{name:string, json:?string, offset:int, length:int, band:string, parent:?int}>
     */
    private static function asScorerSees( string $markup, array $scan ): array
    {
        if ( ! str_starts_with( ltrim( $markup ), '<!-- wp:divi/placeholder' ) ) {
            return $scan;
        }

        return array_values( array_filter( $scan, static fn ( array $b ): bool => ! ( null === $b['parent'] && 'divi/placeholder' === $b['name'] ) ) );
    }
}
