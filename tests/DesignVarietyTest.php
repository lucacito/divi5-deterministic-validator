<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignVariety;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';

/**
 * The variety advisory counts structure; it never judges taste. Pages here are built from small generated sections (so every
 * rule is exercised in isolation) plus real recipe markup (so the clean case is a page the plugin itself teaches).
 */
class DesignVarietyTest extends TestCase
{
    /** @param array<string,mixed> $attrs */
    private function block( string $name, array $attrs, string $inner = '' ): string
    {
        $json = json_encode( [] === $attrs ? new \stdClass() : $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        return '' === $inner
            ? "<!-- wp:divi/{$name} {$json} /-->"
            : "<!-- wp:divi/{$name} {$json} -->{$inner}<!-- /wp:divi/{$name} -->";
    }

    /** @param list<string> $modules module names without the divi/ prefix */
    private function column( array $modules, string $type = '4_4' ): string
    {
        $inner = '';
        foreach ( $modules as $m ) {
            $inner .= $this->block( $m, [ 'builderVersion' => '5.14.0' ] );
        }

        return $this->block( 'column', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => $type ] ] ] ] ], $inner );
    }

    /**
     * @param list<list<string>> $columns module list per column of ONE row (the structure is derived from the column count)
     */
    private function row( array $columns ): string
    {
        $types = [ 1 => [ '4_4' ], 2 => [ '1_2', '1_2' ], 3 => [ '1_3', '1_3', '1_3' ], 4 => [ '1_4', '1_4', '1_4', '1_4' ] ][ count( $columns ) ];
        $inner = '';
        foreach ( $columns as $i => $mods ) {
            $inner .= $this->column( $mods, $types[ $i ] );
        }

        return $this->block( 'row', [ 'module' => [ 'advanced' => [ 'columnStructure' => [ 'desktop' => [ 'value' => implode( ',', $types ) ] ] ] ] ], $inner );
    }

    /**
     * @param list<list<list<string>>> $rows
     * @param array<string,mixed>      $bg  the section background value (e.g. ['color'=>'#fff'])
     * @param array<string,mixed>      $decoration extra module.decoration keys (e.g. ['boxShadow'=>[...]])
     */
    private function section( array $rows, array $bg = [], array $decoration = [] ): string
    {
        $dec = $decoration;
        if ( $bg ) {
            $dec['background'] = [ 'desktop' => [ 'value' => $bg ] ];
        }
        $inner = '';
        foreach ( $rows as $r ) {
            $inner .= $this->row( $r );
        }

        return $this->block( 'section', [ 'module' => [ 'decoration' => $dec ] ], $inner );
    }

    private function page( string ...$sections ): string
    {
        return '<!-- wp:divi/placeholder -->' . implode( '', $sections ) . '<!-- /wp:divi/placeholder -->';
    }

    private const CARD    = [ 'image', 'heading', 'text' ];
    private const SHADOW  = [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'style' => 'preset1' ] ] ] ];
    private const GRADIENT = [ 'gradient' => [ 'enabled' => 'on', 'direction' => '90deg', 'stops' => [ [ 'position' => 0, 'color' => '#111111' ], [ 'position' => 100, 'color' => '#444444' ] ] ] ];

    private function cardSection( array $bg = [ 'color' => '#ffffff' ], array $dec = [] ): string
    {
        return $this->section( [ [ self::CARD, self::CARD, self::CARD ] ], $bg, $dec );
    }

    /** @return list<string> */
    private function codes( ?array $advice ): array
    {
        return null === $advice ? [] : array_map( static fn ( array $f ): string => $f['code'], $advice['findings'] );
    }

    /** @return array<string,mixed>|null */
    private function finding( ?array $advice, string $code ): ?array
    {
        foreach ( $advice['findings'] ?? [] as $f ) {
            if ( $f['code'] === $code ) {
                return $f;
            }
        }

        return null;
    }

    public function testThreeIdenticalCardSectionsFlagRepeatedAndCardOveruse(): void
    {
        $advice = DesignVariety::advise( $this->page( $this->cardSection(), $this->cardSection(), $this->cardSection() ) );
        $this->assertNotNull( $advice );
        $this->assertContains( 'REPEATED_LAYOUT', $this->codes( $advice ) );
        $this->assertContains( 'CARD_GRID_OVERUSE', $this->codes( $advice ) );
        $this->assertSame( [ 0, 1, 2 ], $this->finding( $advice, 'REPEATED_LAYOUT' )['sections'] );
        $this->assertSame( [ 0, 1, 2 ], $this->finding( $advice, 'CARD_GRID_OVERUSE' )['sections'] );
        $this->assertStringContainsString( 'sections 1, 2 and 3', $this->finding( $advice, 'REPEATED_LAYOUT' )['message'] );
        $this->assertNotSame( '', $advice['message'] );
    }

    public function testVariedPageIsClean(): void
    {
        // Real recipes, default design language. Why this is clean: hero-cta opens with a background image (focal, so no
        // NO_FOCAL_ELEMENT); the five recipes have five different skeletons (no adjacent repeat, none three times); none of
        // them is a card grid by fingerprint (0 of 5, under CARD_SHARE); backgrounds are image / solid, with the image
        // enough to avoid FLAT_BACKGROUNDS; and split-image-text and card-grid-3 carry a shadow or scroll effect (depth).
        $markup = '';
        foreach ( [ 'hero-cta', 'split-image-text', 'card-grid-3', 'stats-counter', 'newsletter-social' ] as $name ) {
            $recipe = SectionRecipes::recipe( $name );
            $this->assertNotNull( $recipe, $name );
            $markup .= $recipe;
        }
        $content = $this->page( $markup );
        $fp      = DesignVariety::fingerprints( $content );
        $this->assertCount( 5, $fp );
        $this->assertNull( DesignVariety::advise( $content ), json_encode( [ $fp, DesignVariety::advise( $content ) ] ) );
    }

    public function testTwoSectionPageIsSilent(): void
    {
        $this->assertNull( DesignVariety::advise( $this->page( $this->cardSection(), $this->cardSection() ) ) );
        $this->assertSame( 3, DesignVariety::MIN_SECTIONS );
    }

    public function testGarbageAndEmptyContentReturnNullAndNeverThrow(): void
    {
        foreach ( [ '', '   ', '<!-- wp:divi/section', 'plain text', '<!-- wp:divi/section {broken --><!-- /wp:divi/section -->', str_repeat( 'x', 5 * 1024 * 1024 ), '{"a":1}' ] as $garbage ) {
            $this->assertNull( DesignVariety::advise( $garbage ) );
            $this->assertNull( DesignVariety::advise( $garbage, $garbage ) );
        }
        $this->assertNull( DesignVariety::fingerprints( str_repeat( 'x', 5 * 1024 * 1024 ) ) );
        $this->assertNull( DesignVariety::fingerprints( '<!-- wp:divi/section' ) );
        $this->assertSame( [], DesignVariety::fingerprints( '' ) ?? [] );
    }

    public function testPlainFirstSectionFlagsNoFocal(): void
    {
        $small = $this->block( 'heading', [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '20px' ] ] ] ] ] ] ] );
        $first = $this->block( 'section', [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '#ffffff' ] ] ] ] ] ] ,
            $this->block( 'row', [], $this->block( 'column', [], $small ) ) );
        $advice = DesignVariety::advise( $this->page( $first, $this->section( [ [ [ 'text' ] ] ], [ 'color' => '#eeeeee' ] ), $this->section( [ [ [ 'text', 'image' ], [ 'text' ] ] ], [ 'color' => '#dddddd' ] ) ) );
        $f = $this->finding( $advice, 'NO_FOCAL_ELEMENT' );
        $this->assertNotNull( $f );
        $this->assertSame( [ 0 ], $f['sections'] );
        $this->assertStringContainsString( 'section 1', $f['message'] );
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function headingSizes(): array
    {
        return [
            '5vw is focal'           => [ '5vw', true ],
            '4vw is focal'           => [ '4vw', true ],
            '3.9vw is not'           => [ '3.9vw', false ],
            '48px is focal'          => [ '48px', true ],
            '47px is not'            => [ '47px', false ],
            '3em is focal'           => [ '3em', true ],
            '2.9em is not'           => [ '2.9em', false ],
            '3rem is focal'          => [ '3rem', true ],
            '6vh is focal'           => [ '6vh', true ],
            '5vh is not'             => [ '5vh', false ],
            'bare number is px'      => [ '64', true ],
            'clamp uses its maximum' => [ 'clamp(24px, 5vw, 72px)', true ],
            'clamp small'            => [ 'clamp(14px, 2vw, 30px)', false ],
            'garbage is not'         => [ 'huge', false ],
            '20px is not'            => [ '20px', false ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'headingSizes' )]
    public function testLargeHeadingIsFocal( string $size, bool $focal ): void
    {
        $heading = $this->block( 'heading', [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $size ] ] ] ] ] ] ] );
        $first   = $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], $heading ) ) );
        $page    = $this->page( $first, $this->section( [ [ [ 'text' ] ] ], [ 'color' => '#eeeeee' ] ), $this->section( [ [ [ 'text' ], [ 'text' ] ] ], [ 'color' => '#dddddd' ] ) );
        $this->assertSame( $focal, DesignVariety::fingerprints( $page )[0]['focal'] );
        $this->assertSame( ! $focal, in_array( 'NO_FOCAL_ELEMENT', $this->codes( DesignVariety::advise( $page ) ), true ) );
    }

    public function testHeadingFontInsideATextModuleAndOtherFocalSourcesCount(): void
    {
        $text = $this->block( 'text', [ 'content' => [ 'decoration' => [ 'headingFont' => [ 'h1' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '80px' ] ] ] ] ] ] ] ] );
        $this->assertTrue( DesignVariety::fingerprints( $this->page( $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], $text ) ) ) ) )[0]['focal'] );
        $this->assertTrue( DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'image' ] ] ] ) ) )[0]['focal'], 'an image module' );
        $this->assertTrue( DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'text' ] ] ], self::GRADIENT ) ) )[0]['focal'], 'a gradient' );
        $this->assertTrue( DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'text' ] ] ], [ 'image' => [ 'url' => 'https://example.com/a.jpg' ] ] ) ) )[0]['focal'], 'a background image' );
        $this->assertFalse( DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'text' ] ] ], [ 'color' => '#fff' ] ) ) )[0]['focal'] );
    }

    public function testEditThatChangesOneSectionIsSilentOnARepetitivePage(): void
    {
        $before = $this->page( $this->cardSection(), $this->cardSection(), $this->cardSection() );
        $after  = $this->page( $this->cardSection(), $this->cardSection(), $this->section( [ [ self::CARD, self::CARD, self::CARD ] ], self::GRADIENT ) );
        $this->assertNotNull( DesignVariety::advise( $before ), 'the repetitive page is flagged on its own' );
        $this->assertNull( DesignVariety::advise( $after, $before ), 'one section changed: silent' );
        $this->assertNull( DesignVariety::advise( $before, $before ), 'nothing changed: silent' );
        // Two sections changed: speaks again.
        // (The fingerprint is structural: a color value alone is not a change, a background KIND or a layout is.)
        $two = $this->page( $this->cardSection(), $this->section( [ [ self::CARD, self::CARD, self::CARD ] ], self::GRADIENT ), $this->section( [ [ self::CARD, self::CARD, self::CARD ] ], [ 'image' => [ 'url' => 'https://example.com/a.jpg' ] ] ) );
        $this->assertNotNull( DesignVariety::advise( $two, $before ) );
        $recolored = $this->page( $this->cardSection(), $this->cardSection( [ 'color' => '#abcdef' ] ), $this->cardSection( [ 'color' => '#123456' ] ) );
        $this->assertNull( DesignVariety::advise( $recolored, $before ), 'only color values changed: no structural change, silent' );
        // A different section count: speaks (a section was added).
        $added = $this->page( $this->cardSection(), $this->cardSection(), $this->cardSection(), $this->cardSection() );
        $this->assertNotNull( DesignVariety::advise( $added, $before ) );
        // A previous that cannot be parsed does not silence anything.
        $this->assertNotNull( DesignVariety::advise( $before, 'garbage' ) );
    }

    public function testFindingsSuggestRecipesFromOtherLayouts(): void
    {
        $advice = DesignVariety::advise( $this->page( $this->cardSection(), $this->cardSection(), $this->cardSection() ) );
        $names  = SectionRecipes::names();
        $layoutOf = static function ( string $name ): ?string {
            foreach ( json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $r ) {
                if ( $r['name'] === $name ) {
                    return $r['tags']['layout'] ?? null;
                }
            }

            return null;
        };
        $seen = 0;
        foreach ( $advice['findings'] as $f ) {
            if ( ! in_array( $f['code'], [ 'REPEATED_LAYOUT', 'CARD_GRID_OVERUSE' ], true ) ) {
                continue; // the other codes choose by what fixes them (testSuggestionsFixTheFinding)
            }
            $this->assertLessThanOrEqual( 3, count( $f['try'] ) );
            foreach ( $f['try'] as $name ) {
                ++$seen;
                $this->assertContains( $name, $names );
                $this->assertNotSame( 'grid', $layoutOf( $name ), "$name is a grid recipe but the flagged sections are grids" );
            }
        }
        $this->assertGreaterThan( 0, $seen );
    }

    public function testSameInputSameOutput(): void
    {
        $page = $this->page( $this->cardSection(), $this->cardSection(), $this->cardSection(), $this->section( [ [ [ 'text' ] ] ] ) );
        $a    = DesignVariety::advise( $page );
        $this->assertSame( $a, DesignVariety::advise( $page ) );
        $this->assertSame( DesignVariety::fingerprints( $page ), DesignVariety::fingerprints( $page ) );
    }

    public function testFlatBackgrounds(): void
    {
        $mk = fn ( array $bg ) => $this->section( [ [ [ 'text' ] ] ], $bg );
        $split = $this->section( [ [ [ 'text' ], [ 'image' ] ] ], [ 'color' => '#fff' ] );
        $three = $this->section( [ [ [ 'text' ], [ 'text' ], [ 'text' ] ] ], [ 'color' => '#fff' ] );
        $this->assertContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $this->page( $mk( [ 'color' => '#fff' ] ), $split, $three ) ) ) );
        $this->assertContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $this->page( $mk( [] ), $split, $three ) ) ), 'solid and none together are flat' );
        // One gradient section is enough variety.
        $this->assertNotContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $this->page( $mk( self::GRADIENT ), $split, $three ) ) ) );
        $this->assertNotContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $this->page( $mk( [ 'image' => [ 'url' => 'https://example.com/a.jpg' ] ] ), $split, $three ) ) ) );
        $f = $this->finding( DesignVariety::advise( $this->page( $mk( [ 'color' => '#fff' ] ), $split, $three ) ), 'FLAT_BACKGROUNDS' );
        $this->assertSame( [ 0, 1, 2 ], $f['sections'], 'a page-level finding lists every section' );
    }

    public function testBackgroundFingerprintOrder(): void
    {
        $page = $this->page(
            $this->section( [ [ [ 'text' ] ] ], self::GRADIENT + [ 'image' => [ 'url' => 'https://example.com/a.jpg' ], 'color' => '#fff' ] ),
            $this->section( [ [ [ 'text' ] ] ], [ 'image' => [ 'url' => 'https://example.com/a.jpg' ], 'color' => '#fff' ] ),
            $this->section( [ [ [ 'text' ] ] ], [ 'color' => '#fff' ] ),
            $this->section( [ [ [ 'text' ] ] ], [ 'gradient' => [ 'enabled' => 'off' ], 'image' => [ 'url' => '' ] ] ),
            $this->section( [ [ [ 'text' ] ] ] )
        );
        $this->assertSame( [ 'gradient', 'image', 'solid', 'none', 'none' ], array_column( DesignVariety::fingerprints( $page ), 'background' ) );
    }

    public function testNoMotionOrDepthHonoursTheMinimumPageSize(): void
    {
        $plain = static fn ( DesignVarietyTest $t, int $n ): string => $t->page(
            ...array_map( static fn ( int $i ): string => $t->section( [ [ array_fill( 0, 1 + $i % 3, 'text' ) ] ], [ 'color' => '#' . str_repeat( dechex( 10 + $i ), 3 ) ] ), range( 0, $n - 1 ) )
        );
        $this->assertSame( 5, DesignVariety::MOTION_MIN_SECTIONS );
        $this->assertNotContains( 'NO_MOTION_OR_DEPTH', $this->codes( DesignVariety::advise( $plain( $this, 4 ) ) ), 'four sections: too short to ask' );
        $this->assertContains( 'NO_MOTION_OR_DEPTH', $this->codes( DesignVariety::advise( $plain( $this, 5 ) ) ), 'five sections: asked' );
        foreach ( [
            'boxShadow' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'style' => 'preset1' ] ] ] ],
            'transform' => [ 'transform' => [ 'desktop' => [ 'value' => [ 'rotate' => [ 'z' => '3deg' ] ] ] ] ],
            'position'  => [ 'position' => [ 'desktop' => [ 'value' => [ 'mode' => 'absolute' ] ] ] ],
            'animation' => [ 'animation' => [ 'desktop' => [ 'value' => [ 'style' => 'fade' ] ] ] ],
            'scroll'    => [ 'scroll' => [ 'desktop' => [ 'value' => [ 'verticalMotion' => [ 'enable' => 'on' ] ] ] ] ],
        ] as $key => $dec ) {
            $sections = [];
            for ( $i = 0; $i < 5; ++$i ) {
                $sections[] = $this->section( [ [ array_fill( 0, 1 + $i % 3, 'text' ) ] ], [ 'color' => '#' . str_repeat( dechex( 10 + $i ), 3 ) ], 4 === $i ? $dec : [] );
            }
            $fp = DesignVariety::fingerprints( $this->page( ...$sections ) );
            $this->assertTrue( $fp[4]['depth'], $key );
            $this->assertFalse( $fp[0]['depth'], $key );
            $this->assertNotContains( 'NO_MOTION_OR_DEPTH', $this->codes( DesignVariety::advise( $this->page( ...$sections ) ) ), $key );
        }
    }

    public function testDepthOnAnElementDecorationAndNotOnAGradientStopPosition(): void
    {
        $withShadowOnElement = $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], $this->block( 'button', [ 'button' => [ 'decoration' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'style' => 'preset2' ] ] ] ] ] ] ) ) ) );
        $this->assertTrue( DesignVariety::fingerprints( $this->page( $withShadowOnElement ) )[0]['depth'] );
        // A gradient stop has a "position" key but it is not under a decoration key.
        $this->assertFalse( DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'text' ] ] ], self::GRADIENT ) ) )[0]['depth'] );
    }

    public function testCardDetectionNeedsThreeIdenticalNonEmptyColumns(): void
    {
        $fp = static fn ( DesignVarietyTest $t, array $row ): array => DesignVariety::fingerprints( $t->page( $t->section( [ $row ] ) ) )[0];
        $this->assertTrue( $fp( $this, [ self::CARD, self::CARD, self::CARD ] )['cards'] );
        $this->assertTrue( $fp( $this, [ self::CARD, self::CARD, self::CARD, self::CARD ] )['cards'] );
        $this->assertFalse( $fp( $this, [ self::CARD, self::CARD ] )['cards'], 'two columns are not a card grid' );
        $this->assertFalse( $fp( $this, [ self::CARD, self::CARD, [ 'image', 'text' ] ] )['cards'], 'one differs' );
        $this->assertFalse( $fp( $this, [ [], [], [] ] )['cards'], 'empty columns are not cards' );
    }

    public function testLayoutStringJoinsStructuresAndCollapsesRepeatedModules(): void
    {
        $fp = DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'heading' ] ], [ [ 'text', 'text', 'image' ], [ 'text' ] ] ] ) ) )[0];
        // Structures of every row, then the first row's modules (consecutive repeats collapsed).
        $this->assertSame( '4_4 / 1_2,1_2|heading', $fp['layout'] );
        $fp2 = DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'text', 'text', 'image' ], [ 'text' ] ] ] ) ) )[0];
        $this->assertSame( '1_2,1_2|text>image>text', $fp2['layout'] );
    }

    public function testSectionWithoutARowDoesNotCrash(): void
    {
        $bare = $this->block( 'section', [], $this->column( [ 'text' ] ) );
        $fp   = DesignVariety::fingerprints( $this->page( $bare, $bare, $bare ) );
        $this->assertCount( 3, $fp );
        $this->assertSame( 'stacked', DesignVariety::tagLayout( $fp[0] ) );
        $this->assertIsArray( DesignVariety::advise( $this->page( $bare, $bare, $bare ) ) );
        $empty = $this->block( 'section', [] );
        $this->assertCount( 1, DesignVariety::fingerprints( $this->page( $empty ) ) );
    }

    public function testTagLayoutMapsFingerprintsToRecipeVocabulary(): void
    {
        $fp = static fn ( DesignVarietyTest $t, array $rows ): array => DesignVariety::fingerprints( $t->page( $t->section( $rows ) ) )[0];
        $this->assertSame( 'grid', DesignVariety::tagLayout( $fp( $this, [ [ self::CARD, self::CARD, self::CARD ] ] ) ) );
        $this->assertSame( 'centered', DesignVariety::tagLayout( $fp( $this, [ [ [ 'heading' ] ] ] ) ) );
        $this->assertSame( 'split', DesignVariety::tagLayout( $fp( $this, [ [ [ 'text' ], [ 'image' ] ] ] ) ) );
        $this->assertSame( 'grid', DesignVariety::tagLayout( $fp( $this, [ [ [ 'text' ], [ 'text', 'image' ], [ 'button' ] ] ] ) ), '3+ columns' );
        $this->assertSame( 'split', DesignVariety::tagLayout( $fp( $this, [ [ [ 'heading' ] ], [ [ 'text' ], [ 'image' ] ] ] ) ), 'the widest row decides' );
        $this->assertSame( 'stacked', DesignVariety::tagLayout( [ 'layout' => '|', 'cards' => false, 'background' => 'none', 'focal' => false, 'depth' => false ] ) );
        $this->assertSame( 'stacked', DesignVariety::tagLayout( [ 'layout' => '', 'cards' => false, 'background' => 'none', 'focal' => false, 'depth' => false ] ) );
        foreach ( [ 'grid', 'centered', 'split', 'stacked' ] as $tag ) {
            $this->assertContains( $tag, SectionRecipes::LAYOUTS );
        }
    }

    public function testFindingsNeverSuggestAFlaggedLayout(): void
    {
        // Two-column split sections repeated: the suggestions must not be 'split' recipes.
        $split = fn () => $this->section( [ [ [ 'text' ], [ 'image' ] ] ], [ 'color' => '#ffffff' ] );
        $advice = DesignVariety::advise( $this->page( $split(), $split(), $split() ) );
        $rows = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        $byName = [];
        foreach ( $rows as $r ) {
            $byName[ $r['name'] ] = $r['tags']['layout'] ?? null;
        }
        $f = $this->finding( $advice, 'REPEATED_LAYOUT' );
        $this->assertNotNull( $f );
        $this->assertNotEmpty( $f['try'] );
        foreach ( $f['try'] as $name ) {
            $this->assertNotSame( 'split', $byName[ $name ] );
        }
    }

    /** a section holding a column that holds a row of $n columns, each with $mods */
    private function nestedSection( int $n, array $mods, string $rowName = 'row', array $bg = [ 'color' => '#ffffff' ] ): string
    {
        $cols = '';
        for ( $i = 0; $i < $n; ++$i ) {
            $inner = '';
            foreach ( $mods as $m ) {
                $inner .= $this->block( $m, [ 'builderVersion' => '5.14.0' ] );
            }
            $cols .= $this->block( 'column' === $rowName ? 'column' : 'column-inner', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '1_3' ] ] ] ] ], $inner );
        }
        $nested = $this->block( $rowName, [], $cols );

        return $this->block( 'section', [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => $bg ] ] ] ] ], $this->block( 'row', [], $this->block( 'column', [], $nested ) ) );
    }

    public function testNestedCardGridsAreDetectedAndFlagged(): void
    {
        foreach ( [ 'row', 'row-inner' ] as $rowName ) {
            $s = fn () => $this->nestedSection( 3, [ 'blurb' ], $rowName );
            $fp = DesignVariety::fingerprints( $this->page( $s() ) )[0];
            $this->assertTrue( $fp['cards'], $rowName );
            $this->assertSame( 'grid', DesignVariety::tagLayout( $fp ) );
            $advice = DesignVariety::advise( $this->page( $s(), $s(), $s() ) );
            $this->assertContains( 'CARD_GRID_OVERUSE', $this->codes( $advice ), $rowName );
            $this->assertContains( 'REPEATED_LAYOUT', $this->codes( $advice ), $rowName );
        }
        $four = DesignVariety::fingerprints( $this->page( $this->nestedSection( 4, [ 'blurb' ], 'row-inner' ) ) )[0];
        $this->assertTrue( $four['cards'] );
    }

    public function testNestedRowsThatAreNotCardGrids(): void
    {
        $this->assertFalse( DesignVariety::fingerprints( $this->page( $this->nestedSection( 2, [ 'blurb' ] ) ) )[0]['cards'], 'two columns' );
        $this->assertFalse( DesignVariety::fingerprints( $this->page( $this->nestedSection( 2, [ 'blurb' ], 'row-inner' ) ) )[0]['cards'] );
        // Three nested columns that differ.
        $cols = $this->block( 'column-inner', [], $this->block( 'blurb', [] ) ) . $this->block( 'column-inner', [], $this->block( 'text', [] ) ) . $this->block( 'column-inner', [], $this->block( 'blurb', [] ) );
        $differ = $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], $this->block( 'row-inner', [], $cols ) ) ) );
        $this->assertFalse( DesignVariety::fingerprints( $this->page( $differ ) )[0]['cards'] );
        // Empty nested columns are not cards either.
        $empty = $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], $this->block( 'row-inner', [], str_repeat( $this->block( 'column-inner', [], '' ), 3 ) ) ) ) );
        $this->assertFalse( DesignVariety::fingerprints( $this->page( $empty ) )[0]['cards'] );
    }

    public function testDirectBlurbGroupAndFlexWidthCardRowsAreDetected(): void
    {
        // blurb per column (direct row)
        $this->assertTrue( DesignVariety::fingerprints( $this->page( $this->section( [ [ [ 'blurb' ], [ 'blurb' ], [ 'blurb' ] ] ] ) ) )[0]['cards'] );
        // group per column: each column holds one group that holds the card modules
        $col = fn () => $this->block( 'column', [], $this->block( 'group', [], $this->block( 'image', [] ) . $this->block( 'text', [] ) ) );
        $grp = $this->block( 'section', [], $this->block( 'row', [], $col() . $col() . $col() ) );
        $this->assertTrue( DesignVariety::fingerprints( $this->page( $grp ) )[0]['cards'] );
        // flex-width row: columnStructure "8_24,8_24,8_24"
        $fcol = fn () => $this->block( 'column', [ 'module' => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => '8_24' ] ] ] ] ], $this->block( 'blurb', [] ) );
        $flex = $this->block( 'section', [], $this->block( 'row', [ 'module' => [ 'advanced' => [ 'columnStructure' => [ 'desktop' => [ 'value' => '8_24,8_24,8_24' ] ] ] ] ], $fcol() . $fcol() . $fcol() ) );
        $fp   = DesignVariety::fingerprints( $this->page( $flex ) )[0];
        $this->assertTrue( $fp['cards'] );
        $this->assertSame( '8_24,8_24,8_24|blurb', $fp['layout'] );
    }

    public function testASectionNestedInsideDoesNotContributeItsRows(): void
    {
        $cols  = str_repeat( $this->block( 'column-inner', [], $this->block( 'blurb', [] ) ), 3 );
        $inner = $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], $this->block( 'row-inner', [], $cols ) ) ) );
        $outer = $this->block( 'section', [], $this->block( 'row', [], $this->block( 'column', [], $inner ) ) );
        $fps   = DesignVariety::fingerprints( $this->page( $outer ) );
        $this->assertFalse( $fps[0]['cards'], 'the outer section does not count rows of a nested section' );
        $this->assertTrue( $fps[1]['cards'], 'the nested section is its own entry' );
    }

    public function testSuggestionsFixTheFinding(): void
    {
        $tags = static function ( string $name ): array {
            return (array) SectionRecipes::tags( $name );
        };
        $names = SectionRecipes::names();

        // FLAT_BACKGROUNDS: only recipes with a gradient or image background.
        $flat = $this->finding( DesignVariety::advise( $this->page( $this->cardSection(), $this->cardSection(), $this->cardSection() ) ), 'FLAT_BACKGROUNDS' );
        $this->assertNotEmpty( $flat['try'] );
        foreach ( $flat['try'] as $name ) {
            $this->assertContains( $name, $names );
            $this->assertContains( $tags( $name )['background'], [ 'gradient', 'image' ], $name );
        }

        // NO_FOCAL_ELEMENT: only hero recipes (even when the plain first section is a grid).
        $plain  = $this->section( [ [ [ 'text' ], [ 'text' ] ] ], [ 'color' => '#ffffff' ] );
        $noFocal = $this->finding( DesignVariety::advise( $this->page( $plain, $this->section( [ [ [ 'text' ] ] ], self::GRADIENT ), $plain ) ), 'NO_FOCAL_ELEMENT' );
        $this->assertNotNull( $noFocal );
        $this->assertNotEmpty( $noFocal['try'] );
        foreach ( $noFocal['try'] as $name ) {
            $this->assertSame( 'hero', $tags( $name )['role'], $name );
        }

        // NO_MOTION_OR_DEPTH: nothing to suggest, and the message says how (shadow, overlap, animation).
        $sections = [];
        for ( $i = 0; $i < 5; ++$i ) {
            $sections[] = $this->section( [ [ array_fill( 0, 1 + $i % 3, 'text' ) ] ], [ 'color' => '#eeeeee' ] );
        }
        $motion = $this->finding( DesignVariety::advise( $this->page( ...$sections ) ), 'NO_MOTION_OR_DEPTH' );
        $this->assertNotNull( $motion );
        $this->assertSame( [], $motion['try'] );
        foreach ( [ 'box shadow', 'overlap', 'animation', 'get_style_guide' ] as $word ) {
            $this->assertStringContainsString( $word, $motion['message'] );
        }
    }

    private function recipePage( string ...$names ): string
    {
        $m = '';
        foreach ( $names as $n ) {
            $m .= SectionRecipes::recipe( $n );
        }

        return $this->page( $m );
    }

    public function testNoFindingEverSuggestsTheRecipeThatWasFlagged(): void
    {
        $varied = $this->section( [ [ [ 'text' ], [ 'image' ] ] ], self::GRADIENT, self::SHADOW );
        foreach ( SectionRecipes::names() as $name ) {
            $markup = (string) SectionRecipes::recipe( $name );
            $page   = $this->page( $markup . $markup . $markup . $varied );
            $advice = DesignVariety::advise( $page );
            $this->assertNotNull( $advice, "$name three times is repetitive" );
            foreach ( $advice['findings'] as $f ) {
                $this->assertNotContains( $name, $f['try'], "$name was flagged by {$f['code']} and must not be offered as the fix" );
                foreach ( $f['try'] as $t ) {
                    $this->assertContains( $t, SectionRecipes::names() );
                }
            }
        }
        // The reviewer's scenario: hero, three card-grid-3, a closing section.
        $advice = DesignVariety::advise( $this->recipePage( 'hero-cta', 'card-grid-3', 'card-grid-3', 'card-grid-3', 'newsletter-social' ) );
        $f      = $this->finding( $advice, 'REPEATED_LAYOUT' );
        $this->assertSame( [ 1, 2, 3 ], $f['sections'] );
        $this->assertNotContains( 'card-grid-3', $f['try'] );
        $this->assertNotEmpty( $f['try'] );
    }

    public function testASliderOrHeaderFirstSectionIsFocal(): void
    {
        $plain = $this->section( [ [ [ 'text' ] ] ], [ 'color' => '#ffffff' ] );
        $other = $this->section( [ [ [ 'text' ], [ 'text' ] ] ], [ 'color' => '#eeeeee' ] );
        $this->assertSame( [], array_values( array_diff( DesignVariety::FOCAL_MODULES, array_merge( \Divi5Validator\SchemaRules::STRUCTURAL_BLOCKS, \Divi5Validator\SchemaRules::LEAF_MODULES ) ) ), 'every focal module is one the validator accepts' );
        foreach ( DesignVariety::FOCAL_MODULES as $module ) {
            $first = $this->section( [ [ [ preg_replace( '#^divi/#', '', $module ) ] ] ], [ 'color' => '#ffffff' ] );
            $page  = $this->page( $first, $plain, $other );
            $this->assertTrue( DesignVariety::fingerprints( $page )[0]['focal'], $module );
            $this->assertNotContains( 'NO_FOCAL_ELEMENT', $this->codes( DesignVariety::advise( $page ) ), $module );
        }
        // The plugin's own slider recipe and a text-only first section.
        $this->assertTrue( DesignVariety::fingerprints( $this->recipePage( 'slider' ) )[0]['focal'] );
        $this->assertContains( 'NO_FOCAL_ELEMENT', $this->codes( DesignVariety::advise( $this->page( $plain, $other, $plain ) ) ) );
    }

    public function testARealSliderFirstFixturePageIsNotToldToReplaceItsHero(): void
    {
        $fx   = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-7-home.json' ), true );
        $page = (string) $fx['post_content'];
        $fps  = DesignVariety::fingerprints( $page );
        $this->assertNotEmpty( $fps );
        $this->assertStringContainsString( 'slider', $fps[0]['layout'], 'the real home page opens with a slider' );
        $this->assertTrue( $fps[0]['focal'] );
        $this->assertNotContains( 'NO_FOCAL_ELEMENT', $this->codes( DesignVariety::advise( $page ) ) );
    }

    public function testAlternatingTwoSolidColorsIsNotFlat(): void
    {
        $mk = fn ( string $color ) => $this->section( [ [ [ 'text' ], [ 'text' ] ] ], [ 'color' => $color ] );
        $one = $this->page( $mk( '#ffffff' ), $this->section( [ [ [ 'text' ] ] ], [ 'color' => '#ffffff' ] ), $this->section( [ [ [ 'text' ], [ 'text' ], [ 'text' ] ] ], [ 'color' => '#ffffff' ] ) );
        $two = $this->page( $mk( '#ffffff' ), $this->section( [ [ [ 'text' ] ] ], [ 'color' => '#101010' ] ), $this->section( [ [ [ 'text' ], [ 'text' ], [ 'text' ] ] ], [ 'color' => '#ffffff' ] ) );
        $same = $this->page( $mk( '#FFFFFF' ), $this->section( [ [ [ 'text' ] ] ], [ 'color' => ' #ffffff ' ] ), $this->section( [ [ [ 'text' ], [ 'text' ], [ 'text' ] ] ], [ 'color' => 'RGBA(1, 2, 3, 0.5)' ] ) );
        $caseOnly = $this->page( $mk( 'RGBA(1, 2, 3, 0.5)' ), $this->section( [ [ [ 'text' ] ] ], [ 'color' => 'rgba(1,2,3,0.5)' ] ), $this->section( [ [ [ 'text' ], [ 'text' ], [ 'text' ] ] ], [ 'color' => ' rgba(1, 2, 3, 0.5) ' ] ) );
        $this->assertContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $one ) ), 'one color: flat' );
        $this->assertNotContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $two ) ), 'two colors alternate: not flat' );
        $this->assertNotContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $same ) ), 'white and an rgba are two colors' );
        $this->assertContains( 'FLAT_BACKGROUNDS', $this->codes( DesignVariety::advise( $caseOnly ) ), 'case and whitespace differences are one color' );
        // The colors never enter the fingerprints, so a color-only edit stays silent.
        $this->assertSame( [ 'layout', 'cards', 'background', 'focal', 'depth' ], array_keys( DesignVariety::fingerprints( $two )[0] ) );
        $before = $this->page( $this->cardSection( [ 'color' => '#ffffff' ] ), $this->cardSection( [ 'color' => '#ffffff' ] ), $this->cardSection( [ 'color' => '#ffffff' ] ) );
        $after  = $this->page( $this->cardSection( [ 'color' => '#111111' ] ), $this->cardSection( [ 'color' => '#222222' ] ), $this->cardSection( [ 'color' => '#333333' ] ) );
        $this->assertNotNull( DesignVariety::advise( $after ) );
        $this->assertNull( DesignVariety::advise( $after, $before ), 'a color-only edit is silent' );
    }
}
