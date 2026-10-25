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
 * The audit was measured at 895610e (Task 1) and pinned the defect: 60 vanishing cells (30 on the light band, 30 on a panel)
 * and, in Ruling 7's proof scope, 16 framed cells whose hover box fell below 1.5:1 (glass-edge and heavy-block, `control`, on
 * the light band; 12 of them below 1.1:1). Task 3a (the hover fix, Ruling 7: `invert` with a `same` frame wider than 0px keeps
 * the REST frame colour on hover) fixed all 16 with that one condition: the hover frame is the rest frame, so the hover box is
 * never smaller than the rest box. The audit now counts 48 vanishing cells (18 on the light band: the three 0px `invert` kits,
 * which have no frame to keep; 30 on a panel, by design), and testAFramedButtonKeepsItsBoxOnHover holds the proof with zero
 * exceptions. Nothing here is skipped, and no baseline file exists: every remaining vanishing cell is listed in full below.
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

    /**
     * The `invert` kits whose button loses its box on hover on the LIGHT band: the three 0px `same` kits, whose only box is the
     * fill, and the swapped fill melts into the page. Before the Ruling 7 fix glass-edge and heavy-block were here too (their
     * `same` frame followed the swapped fill); they now keep their rest frame on hover.
     */
    private const LIGHT_KITS = [ 'flat-square', 'block-caps', 'pill-caps' ];

    /** The two framed `invert` + `same` kits Ruling 7 moved (a `same` frame wider than 0px). */
    private const FIXED_KITS = [ 'glass-edge', 'heavy-block' ];

    /**
     * sha256 of json_encode() of every KitAdapters::patch() over every (block, role, band in light|surface|accent|dark|panel,
     * ground in none|inverted|accent) of APPLIED that is not null, keyed "kit|block|role|band|ground", taken BEFORE the Ruling 7
     * edit (Task 3a Step 0, at 1d51dc9): the 38 kits other than FIXED_KITS (4,484 patches), and FIXED_KITS' patches other than
     * `divi/button` and `divi/contact-form` (212 patches). The fix may move neither.
     */
    private const UNMOVED_OTHER_KITS = '5e2ef225c5b0efd611624309ef3bbb09e5a5667d27fb4f9fff4d21c373bee9f5';
    private const UNMOVED_OTHER_KITS_COUNT = 4484;
    private const UNMOVED_FIXED_KITS_NON_BUTTON = '2523a6b36e1b6239d06e42a80a33e3a08390b940d00cf93022eaef1fe1ef9c07';
    private const UNMOVED_FIXED_KITS_NON_BUTTON_COUNT = 212;

    /** The same hash over the three 0px `invert` kits (flat-square, block-caps, pill-caps; 354 patches) before the edit. */
    private const UNMOVED_ZERO_WIDTH_INVERT = '1da93c7676edfb93917dffcd4cc1b933777828750aac203ec48cb4f925b37317';
    private const UNMOVED_ZERO_WIDTH_INVERT_COUNT = 354;

    /** The six languages whose `accent-text` is within 1.1:1 of `bg` (the swapped fill and frame melt into the page). */
    private const LIGHT_LANGUAGES = [ 'editorial', 'glass-dark', 'tech-mono', 'corporate-trust', 'soft-saas', 'luxe' ];

    /** The three 0px `invert` kits whose filled button turns transparent on a panel by design (kit-limits Ruling 2). */
    private const PANEL_KITS = [ 'flat-square', 'block-caps', 'pill-caps' ];

    /**
     * The framed kits' cells with a rest box (>= 1.5:1) whose hover box fell below 1.5:1 BEFORE the Ruling 7 fix (measured at
     * 895610e; kept as history, the proof below now demands none). Twelve were below 1.1:1 (the six languages of
     * LIGHT_LANGUAGES); organic-warm (1.19:1) and bold-vibrant (1.41:1) were the other four.
     */
    private const FRAMED_FAILURES_BEFORE_THE_FIX = [
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
        $this->assertCount( 48, $vanishing, 'cells with a box at rest (>= 1.5:1) and none on hover (< 1.1:1), after the Ruling 7 fix (60 at 895610e)' );

        $light = array_values( array_filter( $vanishing, static fn ( string $k ): bool => str_contains( $k, '|light|' ) ) );
        $panel = array_values( array_filter( $vanishing, static fn ( string $k ): bool => str_contains( $k, '|panel|' ) ) );
        $this->assertCount( 18, $light, '30 before the Ruling 7 fix' );
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
        $this->assertSame( $expectedLight, $light, 'light: the three 0px invert kits in the six languages whose accent-text melts into the page' );
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
     * Ruling 7's proof: framed kits (a rest frame wider than 0px) x 10 languages x {light, panel} x both roles, a rest box of at
     * least 1.5:1 is still at least 1.5:1 on hover. Zero exceptions, no baseline file. (The accent band is out of scope: spec
     * issue 3, four tech-mono cells at 1.10:1 for framed `invert` kits with a `text`/`accent` frame, a stated limit.) Before the
     * fix this failed in the 16 cells of FRAMED_FAILURES_BEFORE_THE_FIX.
     */
    public function testAFramedButtonKeepsItsBoxOnHover(): void
    {
        $failures = [];
        $framed   = 0;
        foreach ( self::cells() as $key => [ $rest, $hover, $width ] ) {
            if ( $width <= 0 || str_contains( $key, '|accent|' ) ) {
                continue;
            }
            ++$framed;
            if ( $rest >= self::HAS_BOX && $hover < self::HAS_BOX ) {
                $failures[] = sprintf( '%s rest %.2f hover %.2f', $key, $rest, $hover );
            }
        }
        $this->assertSame( 920, $framed, 'framed cells in scope (light and panel, both roles)' );
        $this->assertSame( [], $failures, 'a framed button that has a box at rest keeps it on hover' );

        $least = INF;
        foreach ( self::FRAMED_FAILURES_BEFORE_THE_FIX as $key ) {
            $least = min( $least, self::cells()[ $key ][1] );
        }
        $this->assertGreaterThanOrEqual( self::HAS_BOX, $least, 'the 16 cells that failed before now pass' );
    }

    /**
     * Every patch the fix may not move is byte-identical to the patch before the edit: the 38 other kits, and the two fixed
     * kits' non-button, non-form patches (Review Focus 4).
     */
    public function testOnlyTheFramedInvertSameHoverFrameMoved(): void
    {
        [ $others, $fixedRest ] = [ self::patchesOf( static fn ( string $id ): bool => ! in_array( $id, self::FIXED_KITS, true ), false ), self::patchesOf( static fn ( string $id ): bool => in_array( $id, self::FIXED_KITS, true ), true ) ];
        $this->assertCount( self::UNMOVED_OTHER_KITS_COUNT, $others );
        $this->assertSame( self::UNMOVED_OTHER_KITS, hash( 'sha256', (string) json_encode( $others ) ), 'the 38 other kits are untouched' );
        $this->assertCount( self::UNMOVED_FIXED_KITS_NON_BUTTON_COUNT, $fixedRest );
        $this->assertSame( self::UNMOVED_FIXED_KITS_NON_BUTTON, hash( 'sha256', (string) json_encode( $fixedRest ) ), 'glass-edge and heavy-block: only buttons and forms move' );

        // The only kits the condition reaches are FIXED_KITS.
        $reached = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            if ( 'invert' === $kit['hover'] && 'same' === $kit['border']['color'] && '0px' !== $kit['border']['width'] ) {
                $reached[] = $id;
            }
        }
        $this->assertSame( self::FIXED_KITS, $reached );
    }

    /** glass-edge and heavy-block: on light, accent and dark the hover frame is the rest frame; fill and label still swap. */
    public function testTheFramedInvertSameHoverKeepsTheRestFrame(): void
    {
        foreach ( self::FIXED_KITS as $id ) {
            $kit = StyleKits::get( $id );
            $this->assertIsArray( $kit );
            foreach ( [ 'light', 'accent', 'dark' ] as $on ) {
                $patch = KitAdapters::patch( 'divi/button', 'control', $on, $kit );
                $this->assertIsArray( $patch );
                $d = $patch['button']['decoration'];
                $restFrame  = $d['border']['desktop']['value']['styles']['all']['color'];
                $restFill   = $d['background']['desktop']['value']['color'];
                $restLabel  = $d['font']['font']['desktop']['value']['color'];
                $this->assertSame( $restFill, $restFrame, "$id on $on: a `same` frame is the fill at rest" );
                $this->assertSame( $restFrame, $d['border']['desktop']['hover']['styles']['all']['color'], "$id on $on: the hover frame keeps the rest frame" );
                $this->assertSame( $restLabel, $d['background']['desktop']['hover']['color'], "$id on $on: the hover fill is the rest label" );
                $this->assertSame( $restFill, $d['font']['font']['desktop']['hover']['color'], "$id on $on: the hover label is the rest fill" );
            }
        }
    }

    /** The three 0px `invert` kits (no frame to keep) are byte-identical to the patches before the edit. */
    public function testTheZeroWidthInvertKitsAreUnchanged(): void
    {
        $zero    = [ 'flat-square', 'block-caps', 'pill-caps' ];
        $patches = self::patchesOf( static fn ( string $id ): bool => in_array( $id, $zero, true ), false );
        $this->assertCount( self::UNMOVED_ZERO_WIDTH_INVERT_COUNT, $patches );
        $this->assertSame( self::UNMOVED_ZERO_WIDTH_INVERT, hash( 'sha256', (string) json_encode( $patches ) ) );
    }

    /**
     * Every non-null KitAdapters::patch() of the kits $pick accepts, keyed "kit|block|role|band|ground" in the order of
     * StyleKits::ids(), APPLIED, the five bands and the grounds (none, inverted, accent); with $skipButtons the `divi/button` and
     * `divi/contact-form` patches are left out.
     *
     * @param callable(string):bool $pick
     * @return array<string,array<string,mixed>>
     */
    private static function patchesOf( callable $pick, bool $skipButtons ): array
    {
        $out = [];
        foreach ( StyleKits::ids() as $id ) {
            if ( ! $pick( $id ) ) {
                continue;
            }
            $kit = StyleKits::get( $id );
            self::assertIsArray( $kit );
            foreach ( KitAdapters::APPLIED as $block => $roles ) {
                if ( $skipButtons && ( 'divi/button' === $block || 'divi/contact-form' === $block ) ) {
                    continue;
                }
                foreach ( $roles as $role ) {
                    foreach ( [ 'light', 'surface', 'accent', 'dark', 'panel' ] as $on ) {
                        foreach ( [ null, 'inverted', 'accent' ] as $ground ) {
                            $patch = KitAdapters::patch( $block, $role, $on, $kit, $ground );
                            if ( null !== $patch ) {
                                $out[ "$id|$block|$role|$on|" . ( $ground ?? '-' ) ] = $patch;
                            }
                        }
                    }
                }
            }
        }

        return $out;
    }

    public function testTheFailingFramedKitsWereTheTwoDefaultsTheFixTouches(): void
    {
        $kits = [];
        foreach ( self::FRAMED_FAILURES_BEFORE_THE_FIX as $key ) {
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
