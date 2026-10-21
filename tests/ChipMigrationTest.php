<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\RuleChipAudit;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/RuleMigrationTest.php';
require_once __DIR__ . '/Support/RuleChipAudit.php';

/**
 * Recipe-level guards for the chips of the section recipes driven by a style kit (marker `chip` on a `divi/icon` icon chip or
 * on a `divi/group` outline tag; rules and chips adapter, plan 2026-10-21, Task 5), the twin of RuleMigrationTest (whose
 * helpers it shares). The chip adapter writes the tag's radius and frame, or the icon chip's radius, fill and glyph colour,
 * so a migrated block must carry none of its own:
 *
 * - own ink: a marked tag holds no `module.decoration.border`; a marked icon no `module.decoration.border`, no
 *   `module.decoration.background` and no `icon.advanced.color`;
 * - band: the marker's `on` equals the band the chip really sits on (light, surface, accent or dark: a chip has no panel
 *   band), and a marked tag paints no ground of its own;
 * - merge trap: after the kit resolves (and, when given, the language), every leaf the patch writes holds exactly the patch's
 *   value and every other leaf is one the recipe may keep (CHIP_KEEPS: padding, the glyph's size, alignment and character);
 * - none bespoke: BESPOKE_CHIPS is empty (cap 0);
 * - prose: a recipe with a chip marker does not describe the tag or icon shape the kit decides.
 */
class ChipMigrationTest extends TestCase
{
    /** The bands a chip may sit on (KitAdapters::CHIP_BANDS). */
    private const BANDS = [ 'light', 'surface', 'accent', 'dark' ];

    /** The leaves of a marked chip the patch decides, by block: a tag's frame and corners; an icon's corners, fill and glyph colour. */
    private const OWN_INK = [
        'divi/group' => [ '/^module\.decoration\.border(\.|$)/' ],
        'divi/icon'  => [ '/^module\.decoration\.(border|background)(\.|$)/', '/^icon\.advanced\.color(\.|$)/' ],
    ];

    /**
     * Leaf paths a marked chip may keep that the patch does not write (regexes over dotted paths; any breakpoint and state):
     * the block version, the preset, the Theme Builder area, the module's advanced and meta settings, layout, sizing, spacing
     * (the chip's padding is its size), position, z-index, animation and attributes, and an icon's size, alignment and
     * character. Anything else, a stray fill or corner at another breakpoint included, fails closed.
     */
    private const CHIP_KEEPS = [
        '/^builderVersion$/',
        '/^modulePreset(\.|$)/',
        '/^themeBuilderArea(\.|$)/',
        '/^module\.(advanced|meta)(\.|$)/',
        '/^module\.decoration\.(layout|sizing|spacing|position|zIndex|animation|attributes)(\.|$)/',
        '/^icon\.advanced\.(size|align)(\.|$)/',
        '/^icon\.innerContent(\.|$)/',
    ];

    /** Chip blocks deliberately NOT driven by a style kit. SHRINK-ONLY and EMPTY since rules and chips Task 5 (cap 0). */
    private const BESPOKE_CHIPS = [];

    private const BESPOKE_CHIPS_MAX = 0;

    /**
     * The 14 chip markers: "<recipe>|<block>|<index among that block name>" => band. Pinned as literals, never read from git or
     * the recipes: 3 icon chips in icon-values and 6 in features-carousel-strip (all on a surface band), 5 outline tags in
     * portfolio-featured-grid (on the page colour). The filled "[Most popular]" badge, the step badges and the timeline dots
     * are shape decisions and stay literal (RuleMigrationTest pins them).
     */
    private const MIGRATED_CHIPS = [
        'icon-values|divi/icon|0'                        => 'surface',
        'icon-values|divi/icon|1'                        => 'surface',
        'icon-values|divi/icon|2'                        => 'surface',
        'features-carousel-strip|divi/icon|0'            => 'surface',
        'features-carousel-strip|divi/icon|1'            => 'surface',
        'features-carousel-strip|divi/icon|2'            => 'surface',
        'features-carousel-strip|divi/icon|3'            => 'surface',
        'features-carousel-strip|divi/icon|4'            => 'surface',
        'features-carousel-strip|divi/icon|5'            => 'surface',
        'portfolio-featured-grid|divi/group|5'           => 'light',
        'portfolio-featured-grid|divi/group|8'           => 'light',
        'portfolio-featured-grid|divi/group|11'          => 'light',
        'portfolio-featured-grid|divi/group|14'          => 'light',
        'portfolio-featured-grid|divi/group|17'          => 'light',
    ];

    /**
     * Phrases that describe the tag or icon shape a style kit now decides (corners, frame), banned from the title, description
     * and `when` of a recipe with a chip marker: the prose must be true under every kit.
     */
    private const CHIP_DECIDED_PHRASES = [
        '/\b(pill|pill-shaped|rounded|round|circular|circle|square|bordered|outlined|framed|hairline)\s+(\w+\s+)?(tags?|chips?|badges?|icons?|labels?)\b/i',
        '/\b(tags?|chips?|badges?|icons?)\s+(with|in)\s+(rounded|square|sharp|hairline|pill)\b/i',
    ];

    /**
     * [role, on] of a chip marker in $attrs, or null (no marker, or any other role). A chip marker without a string `on`
     * throws: the resolver would default it, and a recipe chip must say where it sits.
     *
     * @param array<string,mixed> $attrs
     * @return array{0:string,1:string}|null
     */
    public static function chipMarker( array $attrs ): ?array
    {
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || 'chip' !== ( $m['kit'] ?? null ) ) {
            return null;
        }
        if ( ! is_string( $m['on'] ?? null ) || '' === $m['on'] ) {
            throw new \UnexpectedValueException( 'a chip marker without "on": a recipe chip must name its band, never default' );
        }
        return [ 'chip', (string) $m['on'] ];
    }

    /** How many chip markers the block comments of $markup carry (parsed, so key order does not matter). */
    private static function markerCount( string $markup ): int
    {
        return count( array_filter( RuleMigrationTest::blocks( $markup ), static fn ( array $b ): bool => null !== self::chipMarker( $b[1] ) ) );
    }

    /**
     * (a): what the RAW markup's marked chips still carry of their own ([] = fine).
     *
     * @return list<string>
     */
    public static function ownInkProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( RuleMigrationTest::blocks( $markup ) as [ $name, $attrs, $i ] ) {
            $marker = self::chipMarker( $attrs );
            if ( null === $marker ) {
                continue;
            }
            ++$checked;
            foreach ( array_keys( RuleMigrationTest::leaves( $attrs ) ) as $path ) {
                if ( ! str_starts_with( $path, 'aied.' ) && RuleMigrationTest::matchesAny( $path, self::OWN_INK[ $name ] ?? [ '/^$/' ] ) ) {
                    $out[] = "$label $name#$i (chip on {$marker[1]}): the migrated block must drop its own $path";
                }
            }
        }
        return $out;
    }

    /**
     * (c): every chip marker's `on` must equal the band its nearest ground makes (RecipeLibraryTest's band walk; an inverted
     * panel, `other:text`, is `panel`, which a chip may not sit on), that band must be one a chip takes, and a marked tag
     * must not paint a ground of its own.
     *
     * @return list<string>
     */
    public static function bandProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( RecipeLibraryTest::markerBands( $markup, static fn ( array $a ): ?array => self::chipMarker( $a ) ) as $key => [ $marker, $band ] ) {
            ++$checked;
            $band = 'other:text' === $band ? 'panel' : $band;
            if ( $marker[1] !== $band ) {
                $out[] = "$label $key: chip marker on={$marker[1]}, but the nearest ground makes it $band";
            }
            if ( ! in_array( $band, self::BANDS, true ) ) {
                $out[] = "$label $key: chip on a $band band (it sits on " . implode( '|', self::BANDS ) . ' only)';
            }
        }
        foreach ( RuleMigrationTest::blocks( $markup ) as [ $name, $attrs, $i ] ) {
            if ( 'divi/group' === $name && null !== self::chipMarker( $attrs ) && isset( $attrs['module']['decoration']['background'] ) ) {
                $out[] = "$label $name#$i: a marked tag with a background of its own (the band walk would not see it)";
            }
        }
        return $out;
    }

    /**
     * (d) the merge trap: for every block that carries a chip marker in $raw, the same block (name and index) in $resolved
     * (after the kit $kit and, when given, the language $lang): every leaf the patch writes holds exactly the patch's value,
     * and every other leaf matches CHIP_KEEPS.
     *
     * @param array<string,mixed>      $kit
     * @param array<string,mixed>|null $lang
     * @return list<string>
     */
    public static function mergeTrapProblems( string $label, string $raw, string $resolved, array $kit, ?array $lang, int &$checked = 0 ): array
    {
        $out   = [];
        $after = [];
        foreach ( RuleMigrationTest::blocks( $resolved ) as [ $name, $attrs, $i ] ) {
            $after[ "$name#$i" ] = $attrs;
        }
        foreach ( RuleMigrationTest::blocks( $raw ) as [ $name, $attrs, $i ] ) {
            $marker = self::chipMarker( $attrs );
            if ( null === $marker ) {
                continue;
            }
            ++$checked;
            $on    = $marker[1];
            $where = "$label $name#$i (chip on $on)";
            $got   = $after[ "$name#$i" ] ?? null;
            $bare  = KitResolver::resolve( '<!-- wp:' . $name . ' ' . json_encode( [ 'aied' => [ 'kit' => 'chip', 'on' => $on ] ] ) . ' /-->', $kit );
            if ( null !== $lang ) {
                $bare = RecipeTokens::resolve( $bare, $lang );
            }
            $expect = RuleMigrationTest::blocks( $bare )[0][1] ?? null;
            if ( ! is_array( $got ) || ! is_array( $expect ) || [] === $expect || isset( $got['aied'] ) ) {
                $out[] = "$where: not resolved";
                continue;
            }
            $want = RuleMigrationTest::leaves( $expect );
            foreach ( RuleMigrationTest::leaves( $got ) as $path => $value ) {
                if ( array_key_exists( $path, $want ) ) {
                    if ( $value !== $want[ $path ] ) {
                        $out[] = "$where: $path is " . json_encode( $value, JSON_UNESCAPED_SLASHES ) . ', not the kit\'s ' . json_encode( $want[ $path ], JSON_UNESCAPED_SLASHES );
                    }
                } elseif ( ! RuleMigrationTest::matchesAny( $path, self::CHIP_KEEPS ) ) {
                    $out[] = "$where: keeps $path that the kit does not write";
                }
            }
            foreach ( array_diff_key( $want, RuleMigrationTest::leaves( $got ) ) as $path => $value ) {
                $out[] = "$where: lacks the kit's $path";
            }
        }
        return $out;
    }

    /** The chip markers of the recipe FILE: [parsed from block comments, spelled `"kit":"chip"`]. @return array{0:int,1:int} */
    private static function markersInFile(): array
    {
        $parsed  = 0;
        $spelled = 0;
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            $markup   = (string) $recipe['markup'];
            $parsed  += self::markerCount( $markup );
            $spelled += substr_count( $markup, '"kit":"chip"' );
        }
        return [ $parsed, $spelled ];
    }

    public function testMarkedChipsCarryNoInkOfTheirOwnAndMatchTheirBand(): void
    {
        [ $parsed, $spelled ] = self::markersInFile();
        $this->assertSame( $spelled, $parsed, 'every "kit":"chip" in the file is a top-level marker of a block comment' );
        $own      = [];
        $bands    = [];
        $ownSeen  = 0;
        $bandSeen = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw   = (string) SectionRecipes::rawMarkup( $name );
            $own   = array_merge( $own, self::ownInkProblems( $name, $raw, $ownSeen ) );
            $bands = array_merge( $bands, self::bandProblems( $name, $raw, $bandSeen ) );
        }
        $this->assertSame( 14, $parsed, 'the 14 migrated chips carry exactly 14 markers' );
        $this->assertSame( $parsed, $ownSeen, 'every chip marker in the file is checked for its own ink' );
        $this->assertSame( $parsed, $bandSeen, 'every chip marker in the file is checked against its band' );
        $this->assertSame( [], $own, 'a migrated chip keeps its own corners, frame, fill or glyph colour' );
        $this->assertSame( [], $bands, 'a chip\'s "on" must name the band it is really drawn on, or the kit picks the wrong colours' );
    }

    public function testMarkedChipsKeepOnlyStructureUnderEveryKitAndLanguage(): void
    {
        $problems = [];
        $blocks   = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            if ( 0 === self::markerCount( $raw ) ) {
                continue;
            }
            foreach ( StyleKits::ids() as $n => $kitId ) {
                $kit      = (array) StyleKits::get( $kitId );
                $seen     = 0;
                $problems = array_merge( $problems, self::mergeTrapProblems( "$name/$kitId", $raw, KitResolver::resolve( $raw, $kit ), $kit, null, $seen ) );
                if ( 0 === $n ) {
                    $blocks += $seen;
                }
                foreach ( DesignLanguages::ids() as $langId ) {
                    $lang = DesignLanguages::get( $langId );
                    $this->assertIsArray( $lang );
                    $problems = array_merge( $problems, self::mergeTrapProblems( "$name/$kitId/$langId", $raw, (string) SectionRecipes::recipe( $name, $langId, $kitId ), $kit, $lang ) );
                }
            }
        }
        $this->assertSame( 14, $blocks, 'the merge trap walks all 14 chip markers' );
        $this->assertSame( self::markersInFile()[0], $blocks, 'the merge trap checks every chip marker in the file' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked chip keeps only structure: its own corners, fill or glyph colour survive the kit\'s deep merge at the breakpoints the kit does not write' );
    }

    /** Every chip the audit sees: "<recipe>|<block>|<index>" => the band of the marker, or null when it carries none. @return array<string,?string> */
    private static function chipBlocks(): array
    {
        $recipes = (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        $out     = [];
        foreach ( RuleChipAudit::chips( $recipes ) as $c ) {
            $out[ RuleChipAudit::key( $c ) ] = $c['marked'] ? $c['on'] : null;
        }
        return $out;
    }

    public function testTheFourteenChipMarkersArePinnedAndNoChipIsBespoke(): void
    {
        $blocks = self::chipBlocks();
        $marked = array_filter( $blocks, static fn ( ?string $on ): bool => null !== $on );
        $pinned = self::MIGRATED_CHIPS;
        ksort( $pinned );
        ksort( $marked );
        $this->assertSame( $pinned, $marked, 'exactly these blocks carry a chip marker, with this band' );
        $this->assertCount( 14, $marked );
        $carriers = array_count_values( array_map( static fn ( string $k ): string => explode( '|', $k )[1], array_keys( $marked ) ) );
        ksort( $carriers );
        $this->assertSame( [ 'divi/group' => 5, 'divi/icon' => 9 ], $carriers );
        $this->assertCount( 3, array_unique( array_map( static fn ( string $k ): string => explode( '|', $k )[0], array_keys( $marked ) ) ), 'the 14 chips sit in 3 recipes' );
        $bespoke = array_keys( array_filter( $blocks, static fn ( ?string $on ): bool => null === $on ) );
        foreach ( $bespoke as $key ) {
            $this->assertArrayHasKey( $key, self::BESPOKE_CHIPS, "$key is a chip with no chip marker: give it one (BESPOKE_CHIPS never grows)" );
        }
        foreach ( array_keys( self::BESPOKE_CHIPS ) as $key ) {
            $this->assertContains( $key, $bespoke, "$key is listed as bespoke but carries a marker or is gone: remove it" );
        }
        $this->assertLessThanOrEqual( self::BESPOKE_CHIPS_MAX, count( self::BESPOKE_CHIPS ), 'shrink-only: BESPOKE_CHIPS can never grow' );
        $this->assertSame( 0, self::BESPOKE_CHIPS_MAX, 'cap 0: every chip in the recipes is kit-driven or a named shape decision' );
        $this->assertSame( [], self::BESPOKE_CHIPS );
        $this->assertSame( [], $bespoke, 'every chip in the recipes carries a chip marker' );
    }

    public function testChipMarkedRecipeProseDoesNotDescribeTheChipShape(): void
    {
        $problems = [];
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            if ( 0 === self::markerCount( (string) $recipe['markup'] ) ) {
                continue;
            }
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                foreach ( self::CHIP_DECIDED_PHRASES as $phrase ) {
                    if ( 1 === preg_match( $phrase, (string) ( $recipe[ $field ] ?? '' ) ) ) {
                        $problems[] = $recipe['name'] . ".$field matches $phrase";
                    }
                }
            }
        }
        $this->assertSame( [], $problems, 'the style kit decides the corners and frame of a tag and an icon badge: describe their role, not their shape' );
        // The phrases catch the wording they were written for, and leave the shipped role prose alone.
        foreach ( [ 'a round accent icon badge, a heading and one line each', 'a small bordered category label under the picture', 'a pill tag', 'an icon with rounded corners', 'square icon badges' ] as $old ) {
            $hit = false;
            foreach ( self::CHIP_DECIDED_PHRASES as $phrase ) {
                $hit = $hit || 1 === preg_match( $phrase, $old );
            }
            $this->assertTrue( $hit, $old );
        }
        foreach ( [ 'an accent icon badge, a heading and one line each', 'a small category label under the picture', 'A row of icon + heading + text value blocks', 'friendly, rounded and dark-glass brands', 'a round numbered marker in the accent colour' ] as $fine ) {
            foreach ( self::CHIP_DECIDED_PHRASES as $phrase ) {
                $this->assertSame( 0, preg_match( $phrase, $fine ), $fine );
            }
        }
    }

    // ---- planted cases: each check fails on an unmigrated recipe with a marker added, and passes on the clean shape ----

    /** The old chip ink of a block as the unmigrated recipes carried it, plus the same at a breakpoint the kit never writes. @return array<string,mixed> */
    private static function oldInk( string $name ): array
    {
        $corners = static fn ( string $px ): array => [ 'topLeft' => $px, 'topRight' => $px, 'bottomRight' => $px, 'bottomLeft' => $px ];
        if ( 'divi/icon' === $name ) {
            return [
                'module' => [ 'decoration' => [
                    'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:accent}}' ] ], 'tablet' => [ 'value' => [ 'color' => '{{aied:color:surface}}' ] ] ],
                    'border'     => [ 'desktop' => [ 'value' => [ 'radius' => $corners( '999px' ) ] ], 'tablet' => [ 'value' => [ 'radius' => $corners( '4px' ) ] ] ],
                ] ],
                'icon'   => [ 'advanced' => [ 'color' => [ 'desktop' => [ 'value' => '{{aied:color:accent-text}}' ], 'tablet' => [ 'value' => '{{aied:color:text}}' ] ] ] ],
            ];
        }
        return [ 'module' => [ 'decoration' => [ 'border' => [
            'desktop' => [ 'value' => [ 'radius' => $corners( '12px' ), 'styles' => [ 'all' => [ 'width' => '1px', 'color' => '{{aied:color:border}}', 'style' => 'solid' ] ] ] ],
            'tablet'  => [ 'value' => [ 'styles' => [ 'all' => [ 'width' => '2px' ] ] ] ],
        ] ] ] ];
    }

    /** $markup with the old chip ink put back on every chip marker. */
    private static function withOldInk( string $markup ): string
    {
        return self::mapMarked( $markup, static fn ( string $name, array $attrs ): array => array_replace_recursive( $attrs, self::oldInk( $name ) ) );
    }

    /** $markup with every chip marker's `on` replaced by $on. */
    private static function withBand( string $markup, string $on ): string
    {
        return self::mapMarked( $markup, static function ( string $name, array $attrs ) use ( $on ): array {
            $attrs['aied']['on'] = $on;
            return $attrs;
        } );
    }

    /** $markup with the own ink of every marked chip removed: every leaf that is not in CHIP_KEEPS and not the marker is dropped. */
    private static function migrate( string $markup ): string
    {
        return self::mapMarked( $markup, static function ( string $name, array $attrs ): array {
            $clean = [];
            foreach ( RuleMigrationTest::leaves( $attrs ) as $path => $value ) {
                if ( str_starts_with( $path, 'aied.' ) || RuleMigrationTest::matchesAny( $path, self::CHIP_KEEPS ) ) {
                    $ref = &$clean;
                    foreach ( explode( '.', $path ) as $k ) {
                        $ref = &$ref[ $k ];
                    }
                    $ref = $value;
                    unset( $ref );
                }
            }
            return $clean;
        } );
    }

    /** @param callable(string,array<string,mixed>):array<string,mixed> $fn applied to every block that carries a chip marker */
    private static function mapMarked( string $markup, callable $fn ): string
    {
        return (string) preg_replace_callback(
            '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s',
            static function ( array $m ) use ( $fn ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( ! is_array( $attrs ) || null === self::chipMarker( $attrs ) ) {
                    return $m[0];
                }
                return str_replace( $m[2], RecipeTokens::encodeJson( $fn( $m[1], $attrs ) ), $m[0] );
            },
            $markup
        );
    }

    /** One recipe per carrier: the icon chips of icon-values and features-carousel-strip, the outline tags of portfolio-featured-grid. */
    private const PLANTED = [ 'icon-values' => 'divi/icon', 'features-carousel-strip' => 'divi/icon', 'portfolio-featured-grid' => 'divi/group' ];

    public function testAnUnmigratedRecipeWithAMarkerFailsEveryCheckAndTheCleanShapePasses(): void
    {
        $kits = [ (array) StyleKits::get( 'quiet-solid' ), (array) StyleKits::get( 'dotted-pill' ), (array) StyleKits::get( 'raw-frame' ) ];
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        foreach ( self::PLANTED as $name => $carrier ) {
            $raw   = (string) SectionRecipes::rawMarkup( $name );
            $count = self::markerCount( $raw );
            $this->assertGreaterThan( 0, $count, "$name is migrated (Task 5)" );
            $planted = self::withOldInk( $raw );
            $this->assertSame( $count, self::markerCount( $planted ) );
            // (a) own ink: the old corners, frame, fill and glyph colour are reported on every marked block.
            $seen = 0;
            $own  = implode( "\n", self::ownInkProblems( $name, $planted, $seen ) );
            $this->assertSame( $count, $seen );
            foreach ( 'divi/icon' === $carrier
                ? [ 'module.decoration.background.desktop.value.color', 'module.decoration.background.tablet.value.color', 'module.decoration.border.desktop.value.radius.topLeft', 'icon.advanced.color.desktop.value', 'icon.advanced.color.tablet.value' ]
                : [ 'module.decoration.border.desktop.value.radius.topLeft', 'module.decoration.border.desktop.value.styles.all.color', 'module.decoration.border.tablet.value.styles.all.width' ] as $path ) {
                $this->assertStringContainsString( "the migrated block must drop its own $path", $own, "$name $path" );
            }
            // (c) the band matches; a wrong band is caught, and so is a band a chip does not take.
            $band = (string) array_values( array_filter( RuleMigrationTest::blocks( $raw ), static fn ( array $b ): bool => null !== self::chipMarker( $b[1] ) ) )[0][1]['aied']['on'];
            $this->assertSame( [], self::bandProblems( $name, $planted ) );
            $wrong = 'light' === $band ? 'dark' : 'light';
            $this->assertStringContainsString( "chip marker on=$wrong, but the nearest ground makes it $band", implode( "\n", self::bandProblems( $name, self::withBand( $raw, $wrong ) ) ), "$name a wrong band" );
            // (d) the merge trap: the tablet leaves the kit never writes survive the merge under every kit and are reported.
            foreach ( $kits as $kit ) {
                $trap = implode( "\n", self::mergeTrapProblems( $name, $planted, KitResolver::resolve( $planted, $kit ), $kit, null ) );
                $this->assertStringContainsString( 'divi/icon' === $carrier ? 'keeps module.decoration.background.tablet.value.color that the kit does not write' : 'keeps module.decoration.border.tablet.value.styles.all.width that the kit does not write', $trap, "$name {$kit['id']}" );
            }

            // The clean shape: the same recipe with its own ink removed passes all three checks, kit-only and in a language.
            $clean = self::migrate( $planted );
            $this->assertSame( $count, self::markerCount( $clean ) );
            $this->assertSame( [], self::ownInkProblems( $name, $clean ) );
            $this->assertSame( [], self::bandProblems( $name, $clean ) );
            foreach ( $kits as $kit ) {
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, KitResolver::resolve( $clean, $kit ), $kit, null ), "$name {$kit['id']} clean" );
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, RecipeTokens::resolve( KitResolver::resolve( $clean, $kit ), $lang ), $kit, $lang ), "$name {$kit['id']} clean in luxe" );
            }
            // The clean shape kept its structure: the chip's padding, and an icon's glyph, size and alignment.
            $first = array_values( array_filter( RuleMigrationTest::blocks( $clean ), static fn ( array $b ): bool => null !== self::chipMarker( $b[1] ) ) )[0][1];
            $this->assertArrayHasKey( 'builderVersion', $first, "$name keeps its version" );
            $this->assertNotEmpty( $first['module']['decoration']['spacing']['desktop']['value']['padding'] ?? null, "$name keeps its chip's padding" );
            if ( 'divi/icon' === $carrier ) {
                $this->assertNotEmpty( $first['icon']['innerContent']['desktop']['value']['unicode'] ?? null, "$name keeps its glyph" );
                $this->assertNotEmpty( $first['icon']['advanced']['size']['desktop']['value'] ?? null, "$name keeps its glyph size" );
                $this->assertArrayNotHasKey( 'color', $first['icon']['advanced'] ?? [], "$name drops its glyph colour" );
            }
        }
    }

    public function testTheMergeTrapCatchesStraysAndOverrides(): void
    {
        $kit  = (array) StyleKits::get( 'quiet-solid' );
        $sec  = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $blk  = static fn ( string $name, array $attrs ): string => '<!-- wp:divi/' . $name . ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
        $run  = static fn ( string $raw ): string => implode( "\n", self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $kit ), $kit, null ) );
        $mark = [ 'kit' => 'chip', 'on' => 'surface' ];

        $icon = [
            'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '10px' ] ] ] ] ] ],
            'icon'   => [ 'advanced' => [ 'size' => [ 'desktop' => [ 'value' => '20px' ] ], 'align' => [ 'desktop' => [ 'value' => 'left' ] ] ], 'innerContent' => [ 'desktop' => [ 'value' => [ 'unicode' => '&#xf518;', 'type' => 'fa', 'weight' => '900' ] ] ] ],
            'builderVersion' => '5.8.0',
            'aied'   => $mark,
        ];
        $tag  = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '4px', 'left' => '10px' ] ] ] ] ] ], 'builderVersion' => '5.8.0', 'aied' => [ 'kit' => 'chip', 'on' => 'light' ] ];
        $this->assertSame( '', $run( $sec( 'surface', $blk( 'icon', $icon ) ) ) );
        $this->assertSame( '', $run( $sec( 'bg', $blk( 'group', $tag ) ) ) );

        $strays = [
            [ 'icon', $icon, 'surface', 'module.decoration.background.tablet.value.color', [ 'module' => [ 'decoration' => [ 'background' => [ 'tablet' => [ 'value' => [ 'color' => '#fff' ] ] ] ] ] ] ],
            [ 'icon', $icon, 'surface', 'module.decoration.border.phone.value.radius.topLeft', [ 'module' => [ 'decoration' => [ 'border' => [ 'phone' => [ 'value' => [ 'radius' => [ 'topLeft' => '9px' ] ] ] ] ] ] ] ],
            [ 'icon', $icon, 'surface', 'icon.advanced.color.tablet.value', [ 'icon' => [ 'advanced' => [ 'color' => [ 'tablet' => [ 'value' => '#000' ] ] ] ] ] ],
            [ 'icon', $icon, 'surface', 'module.decoration.boxShadow.desktop.value.blur', [ 'module' => [ 'decoration' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'blur' => '40px' ] ] ] ] ] ] ],
            [ 'group', $tag, 'bg', 'module.decoration.border.tablet.value.styles.all.width', [ 'module' => [ 'decoration' => [ 'border' => [ 'tablet' => [ 'value' => [ 'styles' => [ 'all' => [ 'width' => '3px' ] ] ] ] ] ] ] ] ],
            [ 'group', $tag, 'bg', 'module.decoration.background.desktop.value.color', [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '#fff' ] ] ] ] ] ] ],
        ];
        foreach ( $strays as [ $name, $base, $ground, $path, $add ] ) {
            $got = $run( $sec( $ground, $blk( $name, array_replace_recursive( $base, $add ) ) ) );
            $this->assertStringContainsString( "keeps $path that the kit does not write", $got, $path );
        }
        // A resolved block whose radius lost the merge (a value other than the kit's) and a block that was never resolved.
        $raw     = $sec( 'surface', $blk( 'icon', $icon ) );
        $changed = preg_replace( '/"topLeft":"[^"]*"/', '"topLeft":"7px"', KitResolver::resolve( $raw, $kit ) );
        $this->assertStringContainsString( 'module.decoration.border.desktop.value.radius.topLeft is "7px", not the kit\'s', implode( "\n", self::mergeTrapProblems( 'p', $raw, (string) $changed, $kit, null ) ) );
        $this->assertStringContainsString( 'not resolved', implode( "\n", self::mergeTrapProblems( 'p', $raw, $raw, $kit, null ) ) );
        // A language pass compares against the patch resolved in that language.
        $lang = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $lang );
        $this->assertSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, $lang ) );
        $this->assertNotSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, (array) DesignLanguages::get( 'luxe' ) ), 'another language\'s colours are not the kit\'s' );
    }

    public function testTheChipBandWalkCatchesWhatItShould(): void
    {
        $sec  = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $chip = static fn ( string $on, string $extra = '' ): string => '<!-- wp:divi/icon {' . $extra . '"aied":{"on":"' . $on . '","kit":"chip"}} /-->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $chip( 'light' ) ) ), 'key order does not matter' );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'surface', $chip( 'surface' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'accent', $chip( 'accent' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'overlay', $chip( 'dark' ) ) ) );
        $this->assertSame( [ 'p divi/icon#0: chip marker on=light, but the nearest ground makes it surface' ], self::bandProblems( 'p', $sec( 'surface', $chip( 'light' ) ) ) );
        // A chip on an inverted panel is `panel`: refused twice (the marker cannot name it, and a chip does not take it).
        $panel = $sec( 'bg', '<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:text}}"}}}}}} -->' . $chip( 'light' ) . '<!-- /wp:divi/group -->' );
        $this->assertSame( [ 'p divi/icon#0: chip marker on=light, but the nearest ground makes it panel', 'p divi/icon#0: chip on a panel band (it sits on light|surface|accent|dark only)' ], self::bandProblems( 'p', $panel ) );
        $this->assertCount( 1, self::bandProblems( 'p', str_replace( '"on":"light"', '"on":"panel"', $panel ) ), 'on=panel agrees with the walk, but a chip takes no panel band' );
        // A chip inside a marked card sits on the card's fill.
        $card = static fn ( string $outerOn, string $inner ): string => '<!-- wp:divi/group {"aied":{"kit":"card","on":"' . $outerOn . '"}} -->' . $inner . '<!-- /wp:divi/group -->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $card( 'light', $chip( 'surface' ) ) ) ) );
        // No ground anywhere fails loudly (never defaults to light).
        $this->assertSame( [ 'p divi/icon#0: chip marker on=light, but the nearest ground makes it none', 'p divi/icon#0: chip on a none band (it sits on light|surface|accent|dark only)' ], self::bandProblems( 'p', $chip( 'light' ) ) );
        // A marked tag with a ground of its own is caught: the walk looks at ancestors only.
        $own = '<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:accent}}"}}}}},"aied":{"kit":"chip","on":"light"}} --><!-- /wp:divi/group -->';
        $this->assertSame( [ 'p divi/group#0: a marked tag with a background of its own (the band walk would not see it)' ], self::bandProblems( 'p', $sec( 'bg', $own ) ) );
        // A marker without a band throws; any other role is no chip marker.
        $this->assertNull( self::chipMarker( [ 'aied' => [ 'kit' => 'rule', 'on' => 'light' ] ] ) );
        $this->assertNull( self::chipMarker( [ 'aied' => [ 'kit' => 'card', 'on' => 'light' ] ] ) );
        foreach ( [ [ 'kit' => 'chip' ], [ 'kit' => 'chip', 'on' => '' ], [ 'kit' => 'chip', 'on' => 1 ] ] as $marker ) {
            try {
                self::chipMarker( [ 'aied' => $marker ] );
                $this->fail( 'a chip marker without a band must fail: ' . json_encode( $marker ) );
            } catch ( \UnexpectedValueException $e ) {
                $this->assertStringContainsString( 'without "on"', $e->getMessage() );
            }
        }
    }
}
