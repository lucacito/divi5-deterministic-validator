<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\Colour;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/Support/Colour.php';

/**
 * The hover audit of the wording-gaps work (plan 2026-10-25, Task 1; spec gap 7): does a button keep a visible box when the
 * pointer is on it? "Box" = the larger of the fill against the band the button sits on and, when the frame is wider than 0px,
 * the frame colour against the band, both as painted (a translucent colour composited over the band; a transparent fill is
 * the band itself, 1.00:1). Over every shipped kit x design language x band {light, accent, panel} x button role.
 *
 * THIS FILE PINS THE CURRENT STATE, measured at 895610e by a pure audit over KitAdapters::patch(). It is deliberately green
 * today and documents the defect; Task 3a (the hover fix, Ruling 7: `invert` with a `same` frame wider than 0px keeps the REST
 * frame colour on hover) flips the numbers below (60 -> 48 vanishing cells, 30 -> 18 on the light band) and replaces
 * testTheFramedHoverFailuresToday with the zero-exception proof testAFramedButtonKeepsItsBoxOnHover. Nothing here is skipped,
 * and no baseline file exists: the failing cells are listed in full below.
 */
class KitHoverVisibilityTest extends TestCase
{
    private const ROLES = [ 'control', 'control-secondary' ];

    /**
     * Band => the palette role the band is painted in. A local literal until Task 4 adds the public KitAdapters::BAND_FILL
     * (TODO(Task 4): read KitAdapters::BAND_FILL instead; the two must agree for these three bands).
     */
    private const BAND_FILL = [ 'light' => 'bg', 'accent' => 'accent', 'panel' => 'text' ];

    /** A button that has a box at rest (at least this contrast against its band)... */
    private const HAS_BOX = 1.5;

    /** ...and none on hover (below this) is a vanishing hover. */
    private const NO_BOX = 1.1;

    /** The five `invert` kits whose button loses its box on hover on the LIGHT band: the frame colour is `same` and follows the swapped fill. */
    private const LIGHT_KITS = [ 'glass-edge', 'heavy-block', 'flat-square', 'block-caps', 'pill-caps' ];

    /** The six languages whose `accent-text` is within 1.1:1 of `bg` (the swapped fill and frame melt into the page). */
    private const LIGHT_LANGUAGES = [ 'editorial', 'glass-dark', 'tech-mono', 'corporate-trust', 'soft-saas', 'luxe' ];

    /** The three 0px `invert` kits whose filled button turns transparent on a panel by design (kit-limits Ruling 2). */
    private const PANEL_KITS = [ 'flat-square', 'block-caps', 'pill-caps' ];

    /**
     * The framed kits' cells with a rest box (>= 1.5:1) whose hover box falls below 1.5:1, as measured today; Task 3a turns this
     * into [] (Ruling 7). The first twelve are the ones below 1.1:1 (the six languages of LIGHT_LANGUAGES); organic-warm (1.19:1)
     * and bold-vibrant (1.41:1) are the other four.
     */
    private const FRAMED_FAILURES_TODAY = [
        'glass-edge|editorial|light|control', 'glass-edge|glass-dark|light|control', 'glass-edge|organic-warm|light|control',
        'glass-edge|tech-mono|light|control', 'glass-edge|corporate-trust|light|control', 'glass-edge|soft-saas|light|control',
        'glass-edge|bold-vibrant|light|control', 'glass-edge|luxe|light|control',
        'heavy-block|editorial|light|control', 'heavy-block|glass-dark|light|control', 'heavy-block|organic-warm|light|control',
        'heavy-block|tech-mono|light|control', 'heavy-block|corporate-trust|light|control', 'heavy-block|soft-saas|light|control',
        'heavy-block|bold-vibrant|light|control', 'heavy-block|luxe|light|control',
    ];

    /** Resolve a colour token or literal of a patch to a hex/rgba string in $language. */
    private static function resolve( string $value, array $language ): string
    {
        return RecipeTokens::resolve( $value, $language );
    }

    /** The band colour of $band in $language as an opaque hex. */
    private static function bandHex( string $band, array $language ): string
    {
        $hex = Colour::opaque( (string) $language['colors'][ self::BAND_FILL[ $band ] ], '#ffffff' );
        self::assertIsString( $hex );

        return $hex;
    }

    /**
     * How much of a box the button has in $state ('rest' or 'hover') against $bandHex: the larger of fill-vs-band and (frame
     * wider than 0px) frame-vs-band. A missing hover value inherits the rest value, as Divi does.
     *
     * @param array<string,mixed> $patch a `divi/button` patch from KitAdapters::patch()
     * @param array<string,mixed> $language
     */
    private static function box( array $patch, string $state, array $language, string $bandHex ): float
    {
        $d    = $patch['button']['decoration'];
        $rest = (string) ( $d['background']['desktop']['value']['color'] ?? KitAdapters::TRANSPARENT );
        $fill = 'hover' === $state ? (string) ( $d['background']['desktop']['hover']['color'] ?? $rest ) : $rest;

        $styles = $d['border']['desktop']['value']['styles'] ?? [];
        $side   = $styles['bottom'] ?? $styles['all'] ?? [ 'width' => '0px', 'color' => '#000000' ];
        $edge   = (string) ( $side['color'] ?? ( $styles['all']['color'] ?? '#000000' ) );
        if ( 'hover' === $state ) {
            $hover = $d['border']['desktop']['hover']['styles'] ?? [];
            $edge  = (string) ( $hover['bottom']['color'] ?? $hover['all']['color'] ?? $edge );
        }

        $painted = Colour::opaque( self::resolve( $fill, $language ), $bandHex );
        self::assertIsString( $painted );
        $box = Colour::contrast( $painted, $bandHex );
        if ( (int) ( $side['width'] ?? 0 ) > 0 ) {
            $frame = Colour::opaque( self::resolve( $edge, $language ), $bandHex );
            self::assertIsString( $frame );
            $box = max( $box, Colour::contrast( $frame, $bandHex ) );
        }

        return $box;
    }

    /**
     * Every cell of the audit: key "kit|language|band|role" => [ rest box, hover box, rest frame width ].
     *
     * @return array<string,array{0:float,1:float,2:int}>
     */
    private static function cells(): array
    {
        $out = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            self::assertIsArray( $kit );
            foreach ( DesignLanguages::ids() as $langId ) {
                $language = DesignLanguages::get( $langId );
                self::assertIsArray( $language );
                foreach ( array_keys( self::BAND_FILL ) as $band ) {
                    $bandHex = self::bandHex( $band, $language );
                    foreach ( self::ROLES as $role ) {
                        $patch = KitAdapters::patch( 'divi/button', $role, $band, $kit );
                        self::assertIsArray( $patch, "$id $role on $band has a patch" );
                        $styles = $patch['button']['decoration']['border']['desktop']['value']['styles'] ?? [];
                        $side   = $styles['bottom'] ?? $styles['all'] ?? [];
                        $out[ "$id|$langId|$band|$role" ] = [
                            self::box( $patch, 'rest', $language, $bandHex ),
                            self::box( $patch, 'hover', $language, $bandHex ),
                            (int) ( $side['width'] ?? 0 ),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<string> the keys of the cells with a rest box that have none on hover */
    private static function vanishing(): array
    {
        $keys = [];
        foreach ( self::cells() as $key => [ $rest, $hover ] ) {
            if ( $rest >= self::HAS_BOX && $hover < self::NO_BOX ) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function testTheAuditCountsTheVanishingHovers(): void
    {
        $cells = self::cells();
        $this->assertCount( 2400, $cells, '40 kits x 10 languages x 3 bands x 2 roles' );

        $vanishing = self::vanishing();
        $this->assertCount( 60, $vanishing, 'cells with a box at rest (>= 1.5:1) and none on hover (< 1.1:1), measured at 895610e' );

        $light = array_values( array_filter( $vanishing, static fn ( string $k ): bool => str_contains( $k, '|light|' ) ) );
        $panel = array_values( array_filter( $vanishing, static fn ( string $k ): bool => str_contains( $k, '|panel|' ) ) );
        $this->assertCount( 30, $light );
        $this->assertCount( 30, $panel );
        $this->assertCount( 0, array_filter( $vanishing, static fn ( string $k ): bool => str_contains( $k, '|accent|' ) ), 'the accent band has no vanishing hover' );
        $this->assertCount( 0, array_filter( $vanishing, static fn ( string $k ): bool => str_ends_with( $k, '|control-secondary' ) ), 'only the primary button loses its box' );

        $expectedLight = [];
        foreach ( self::LIGHT_KITS as $kit ) {
            foreach ( self::LIGHT_LANGUAGES as $lang ) {
                $expectedLight[] = "$kit|$lang|light|control";
            }
        }
        $expectedPanel = [];
        foreach ( self::PANEL_KITS as $kit ) {
            foreach ( DesignLanguages::ids() as $lang ) {
                $expectedPanel[] = "$kit|$lang|panel|control";
            }
        }
        sort( $expectedLight );
        sort( $expectedPanel );
        sort( $light );
        sort( $panel );
        $this->assertSame( $expectedLight, $light, 'light: the five invert kits in the six languages whose accent-text melts into the page' );
        $this->assertSame( $expectedPanel, $panel, 'panel: the three 0px invert kits in all ten languages (kit-limits Ruling 2, by design)' );
    }

    public function testTheSixLanguagesAreThoseWhoseAccentTextMeltsIntoThePage(): void
    {
        $melts = [];
        foreach ( DesignLanguages::ids() as $langId ) {
            $language = DesignLanguages::get( $langId );
            $this->assertIsArray( $language );
            $accentText = Colour::opaque( (string) $language['colors']['accent_text'], '#ffffff' );
            $bg         = Colour::opaque( (string) $language['colors']['bg'], '#ffffff' );
            $this->assertIsString( $accentText );
            $this->assertIsString( $bg );
            if ( Colour::contrast( $accentText, $bg ) < self::NO_BOX ) {
                $melts[] = $langId;
            }
        }
        sort( $melts );
        $expected = self::LIGHT_LANGUAGES;
        sort( $expected );
        // Within 1.1:1 of bg: editorial 1.07, glass-dark 1.00, tech-mono 1.02, corporate-trust 1.00, soft-saas 1.08, luxe 1.02.
        $this->assertSame( $expected, $melts );
    }

    /**
     * Ruling 7's proof scope today: framed kits (a rest frame wider than 0px) x 10 languages x {light, panel} x both roles, a
     * rest box of at least 1.5:1 must still be at least 1.5:1 on hover. TODAY it fails in 16 cells (12 below 1.1:1), all
     * glass-edge and heavy-block `control` on the light band. Task 3a flips this to the empty list and renames the test
     * testAFramedButtonKeepsItsBoxOnHover (zero exceptions, no baseline).
     */
    public function testTheFramedHoverFailuresToday(): void
    {
        $failures = [];
        $framed   = 0;
        $below    = 0;
        foreach ( self::cells() as $key => [ $rest, $hover, $width ] ) {
            if ( $width <= 0 || str_contains( $key, '|accent|' ) ) {
                continue;
            }
            ++$framed;
            if ( $rest >= self::HAS_BOX && $hover < self::HAS_BOX ) {
                $failures[] = $key;
                if ( $hover < self::NO_BOX ) {
                    ++$below;
                }
            }
        }
        $this->assertSame( 920, $framed, 'framed cells in scope (light and panel, both roles)' );
        $this->assertSame( self::FRAMED_FAILURES_TODAY, $failures, 'the framed button cells that lose their box on hover today' );
        $this->assertSame( 12, $below, 'twelve of them are below 1.1:1: glass-edge and heavy-block, control, light, the six LIGHT_LANGUAGES' );
    }

    public function testTheFailingFramedKitsAreTheTwoDefaultsTheFixTouches(): void
    {
        $kits = [];
        foreach ( self::FRAMED_FAILURES_TODAY as $key ) {
            $kits[ explode( '|', $key )[0] ] = true;
        }
        $this->assertSame( [ 'glass-edge', 'heavy-block' ], array_keys( $kits ) );
        foreach ( [ 'glass-edge', 'heavy-block' ] as $id ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            $this->assertSame( 'invert', $kit['hover'], "$id hover" );
            $this->assertSame( 'same', $kit['border']['color'], "$id frame colour follows the fill" );
            $this->assertNotSame( '0px', $kit['border']['width'], "$id has a frame" );
        }
    }
}
