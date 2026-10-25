<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\CarrierAudit;
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
require_once __DIR__ . '/ChipMigrationTest.php';
require_once __DIR__ . '/Support/CarrierAudit.php';

/**
 * Recipe-level guards for the four carriers the carriers adapter added (plan 2026-10-24, Task 5): the accent dividers
 * (`rule-accent` on `divi/divider`), the testimonial (`panel` on `divi/testimonial`), the blurb icons (`chip` on
 * `divi/blurb`) and the counters (`rule-strong` on `divi/number-counter`). The 11 library blocks were migrated by
 * scripts/migrate-carrier-markers.php, never by hand. A migrated block carries none of what the kit now writes:
 *
 * - own ink: a marked divider holds no `divider.advanced.line`; a marked testimonial no `module.decoration.background`,
 *   `border`, `boxShadow` or `transform`; a marked blurb no `imageIcon.decoration.background|border` and no
 *   `imageIcon.advanced.color`; a marked counter no `module.decoration.border` (any breakpoint, any state);
 * - band: the marker's `on` equals the band the shared walk makes (RecipeLibraryTest::markerBands; `other:text` = panel);
 * - merge trap: after the kit (and, when given, the language) every leaf of the block is either a leaf the patch writes,
 *   holding exactly the patch's value, or a leaf of the raw block outside the kit-owned subtrees, holding the raw value
 *   (its tokens resolved); no `aied` key is left;
 * - none bespoke: BESPOKE_CARRIERS is empty (cap 0) and every block CarrierAudit finds carries its marker;
 * - prose: a recipe with a carrier marker does not describe the look the kit decides.
 */
class CarrierMigrationTest extends TestCase
{
    /** The roles each carrier takes in the library (KitAdapters::APPLIED narrowed to the carriers adapter's additions). */
    private const CARRIER_ROLES = [
        'divi/divider'        => [ 'rule-accent' ],
        'divi/testimonial'    => [ 'card', 'panel' ],
        'divi/blurb'          => [ 'chip' ],
        'divi/number-counter' => [ 'rule', 'rule-strong' ],
    ];

    /**
     * The subtrees of a marked carrier that belong to the kit (what the patch can write, at every breakpoint and state): a
     * leaf there is kept only when it is a leaf of the patch itself.
     */
    private const OWN_INK = [
        'divi/divider'        => [ '/^divider\.advanced\.line(\.|$)/' ],
        'divi/testimonial'    => [ '/^module\.decoration\.(background|border|boxShadow|transform)(\.|$)/' ],
        'divi/blurb'          => [ '/^imageIcon\.decoration\.(background|border)(\.|$)/', '/^imageIcon\.advanced\.color(\.|$)/' ],
        'divi/number-counter' => [ '/^module\.decoration\.border(\.|$)/' ],
    ];

    /** Carrier blocks deliberately NOT driven by a style kit. SHRINK-ONLY and EMPTY from the start (cap 0). */
    private const BESPOKE_CARRIERS = [];

    private const BESPOKE_CARRIERS_MAX = 0;

    /** The file size before the carriers were migrated (carriers adapter plan, Task 5): the file must end below it. */
    private const SIZE_BEFORE_CARRIERS = 652430;

    /**
     * The 11 carrier markers: "<recipe>|<block>|<index among that block name>" => "<role> on <band>". Pinned as literals,
     * never read from git or the recipes (plan Task 5 Interfaces; Rulings 1-4).
     */
    private const MIGRATED = [
        'blog-feed|divi/divider|0'                 => 'rule-accent on light',
        'icon-values|divi/divider|0'               => 'rule-accent on light',
        'testimonial|divi/testimonial|0'           => 'panel on light',
        'blurb-grid|divi/blurb|0'                  => 'chip on surface',
        'blurb-grid|divi/blurb|1'                  => 'chip on surface',
        'blurb-grid|divi/blurb|2'                  => 'chip on surface',
        'blurb-grid|divi/blurb|3'                  => 'chip on surface',
        'stats-counter-band|divi/number-counter|0' => 'rule-strong on accent',
        'stats-counter-band|divi/number-counter|1' => 'rule-strong on accent',
        'stats-counter-band|divi/number-counter|2' => 'rule-strong on accent',
        'stats-counter-band|divi/number-counter|3' => 'rule-strong on accent',
    ];

    /** The five recipes that carry the 11 markers. */
    private const RECIPES = [ 'blog-feed', 'icon-values', 'testimonial', 'blurb-grid', 'stats-counter-band' ];

    /**
     * [role, on] of a carrier marker on a block named $name, or null (another block, or a role that carrier does not take
     * here). A carrier marker without a string `on` throws: a recipe carrier must say where it sits.
     *
     * @param array<string,mixed> $attrs
     * @return array{0:string,1:string}|null
     */
    public static function carrierMarker( string $name, array $attrs ): ?array
    {
        $m = $attrs['aied'] ?? null;
        if ( ! isset( self::CARRIER_ROLES[ $name ] ) || ! is_array( $m ) || ! in_array( $m['kit'] ?? null, self::CARRIER_ROLES[ $name ], true ) ) {
            return null;
        }
        if ( ! is_string( $m['on'] ?? null ) || '' === $m['on'] ) {
            throw new \UnexpectedValueException( 'a carrier marker without "on": a recipe carrier must name its band, never default' );
        }
        return [ (string) $m['kit'], (string) $m['on'] ];
    }

    /** @return list<array{0:string,1:array<string,mixed>,2:int}> the opening blocks of $markup that carry a carrier marker */
    private static function marked( string $markup ): array
    {
        return array_values( array_filter( RuleMigrationTest::blocks( $markup ), static fn ( array $b ): bool => null !== self::carrierMarker( $b[0], $b[1] ) ) );
    }

    /**
     * (a): what the RAW markup's marked carriers still carry of their own ([] = fine).
     *
     * @return list<string>
     */
    public static function ownInkProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( self::marked( $markup ) as [ $name, $attrs, $i ] ) {
            ++$checked;
            $marker = (array) self::carrierMarker( $name, $attrs );
            foreach ( array_keys( RuleMigrationTest::leaves( $attrs ) ) as $path ) {
                if ( RuleMigrationTest::matchesAny( $path, self::OWN_INK[ $name ] ) ) {
                    $out[] = "$label $name#$i ({$marker[0]} on {$marker[1]}): the migrated block must drop its own $path";
                }
            }
        }
        return $out;
    }

    /**
     * (b): every carrier marker's `on` must equal the band its nearest ground makes (an inverted panel, `other:text`, is
     * `panel`).
     *
     * @return list<string>
     */
    public static function bandProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out    = [];
        $marker = static function ( array $a ): ?array {
            foreach ( array_keys( self::CARRIER_ROLES ) as $name ) {
                // markerBands gives attributes only; the role list decides, the block name is read from the key below.
                $m = self::carrierMarker( $name, $a );
                if ( null !== $m ) {
                    return $m;
                }
            }
            return null;
        };
        foreach ( RecipeLibraryTest::markerBands( $markup, $marker ) as $key => [ $found, $band ] ) {
            $name = substr( $key, 0, (int) strrpos( $key, '#' ) );
            if ( ! isset( self::CARRIER_ROLES[ $name ] ) || ! in_array( $found[0], self::CARRIER_ROLES[ $name ], true ) ) {
                continue; // a card, panel, chip or rule marker of another carrier: not this test's
            }
            ++$checked;
            $band = 'other:text' === $band ? 'panel' : $band;
            if ( $found[1] !== $band ) {
                $out[] = "$label $key: {$found[0]} marker on={$found[1]}, but the nearest ground makes it $band";
            }
        }
        return $out;
    }

    /**
     * (c) the merge trap: for every block that carries a carrier marker in $raw, the same block (name and index) in
     * $resolved: every leaf is a leaf of the patch (resolved the same way) with exactly its value, or a raw leaf outside
     * OWN_INK with the raw value (its colour and font tokens resolved in $lang; in a language pass an image token only has to be resolved).
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
        foreach ( self::marked( $raw ) as [ $name, $attrs, $i ] ) {
            ++$checked;
            [ $role, $on ] = (array) self::carrierMarker( $name, $attrs );
            $where         = "$label $name#$i ($role on $on)";
            $got           = $after[ "$name#$i" ] ?? null;
            $bare          = KitResolver::resolve( '<!-- wp:' . $name . ' ' . json_encode( [ 'aied' => [ 'kit' => $role, 'on' => $on ] ] ) . ' /-->', $kit );
            $own           = $attrs;
            unset( $own['aied'] );
            $ownMarkup = '<!-- wp:' . $name . ' ' . RecipeTokens::encodeJson( $own ) . ' /-->';
            if ( null !== $lang ) {
                $bare      = RecipeTokens::resolve( $bare, $lang );
                $ownMarkup = RecipeTokens::resolve( $ownMarkup, $lang );
            }
            $expect = RuleMigrationTest::blocks( $bare )[0][1] ?? null;
            if ( ! is_array( $got ) || ! is_array( $expect ) || [] === $expect || isset( $got['aied'] ) ) {
                $out[] = "$where: not resolved";
                continue;
            }
            $want = RuleMigrationTest::leaves( $expect );
            $keep = RuleMigrationTest::leaves( RuleMigrationTest::blocks( $ownMarkup )[0][1] ?? [] );
            $have = RuleMigrationTest::leaves( $got );
            foreach ( $have as $path => $value ) {
                if ( array_key_exists( $path, $want ) ) {
                    if ( $value !== $want[ $path ] ) {
                        $out[] = "$where: $path is " . json_encode( $value, JSON_UNESCAPED_SLASHES ) . ', not the kit\'s ' . json_encode( $want[ $path ], JSON_UNESCAPED_SLASHES );
                    }
                } elseif ( RuleMigrationTest::matchesAny( $path, self::OWN_INK[ $name ] ) ) {
                    $out[] = "$where: keeps $path that the kit does not write";
                } elseif ( ! array_key_exists( $path, $keep ) ) {
                    $out[] = "$where: $path is neither the kit's nor the recipe's";
                } elseif ( null !== $lang && is_string( $keep[ $path ] ) && str_contains( $keep[ $path ], '{{aied:image:' ) ) {
                    // The served recipe (a language pass) resolved its image tokens before the kit step; a kit-only pass did not.
                    if ( ! is_string( $value ) || str_contains( $value, '{{aied:' ) ) {
                        $out[] = "$where: $path keeps an unresolved image token";
                    }
                } elseif ( $value !== $keep[ $path ] ) {
                    $out[] = "$where: $path is " . json_encode( $value, JSON_UNESCAPED_SLASHES ) . ', not the recipe\'s ' . json_encode( $keep[ $path ], JSON_UNESCAPED_SLASHES );
                }
            }
            foreach ( array_diff_key( $want, $have ) as $path => $_ ) {
                $out[] = "$where: lacks the kit's $path";
            }
            foreach ( array_diff_key( $keep, $have ) as $path => $_ ) {
                if ( ! RuleMigrationTest::matchesAny( $path, self::OWN_INK[ $name ] ) ) {
                    $out[] = "$where: lost the recipe's $path";
                }
            }
        }
        return $out;
    }

    /** @return list<array{name:string,markup:string}> */
    private static function library(): array
    {
        return (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
    }

    /** Every carrier block CarrierAudit finds: key => "<role> on <band>" of its marker, or null when it carries none. @return array<string,?string> */
    private static function carrierBlocks(): array
    {
        $out = [];
        foreach ( CarrierAudit::blocks( self::library() ) as $row ) {
            $out[ CarrierAudit::key( $row ) ] = $row['marked'] ? $row['role'] . ' on ' . $row['on'] : null;
        }
        return $out;
    }

    public function testTheElevenMarkersArePinnedAndNothingIsBespoke(): void
    {
        // Parsed markers on the four carriers, read from the block comments (not from the audit).
        $parsed = [];
        foreach ( self::library() as $recipe ) {
            foreach ( self::marked( (string) $recipe['markup'] ) as [ $name, $attrs, $i ] ) {
                $m                                            = (array) self::carrierMarker( $name, $attrs );
                $parsed[ $recipe['name'] . "|$name|$i" ] = $m[0] . ' on ' . $m[1];
            }
        }
        $pinned = self::MIGRATED;
        ksort( $pinned );
        ksort( $parsed );
        $this->assertSame( $pinned, $parsed, 'exactly these blocks carry a carrier marker, with this role and band' );
        $this->assertCount( 11, $parsed );
        $this->assertSame( self::RECIPES, array_values( array_unique( array_map( static fn ( string $k ): string => explode( '|', $k )[0], array_keys( self::MIGRATED ) ) ) ) );

        // Every block the audit finds is marked, and the audit agrees with the pins (its band is the walk's).
        $blocks = self::carrierBlocks();
        ksort( $blocks );
        $this->assertSame( $pinned, $blocks, 'every carrier block of the library carries its marker' );
        $bespoke = array_keys( array_filter( $blocks, static fn ( ?string $m ): bool => null === $m ) );
        $this->assertSame( [], $bespoke );
        $this->assertLessThanOrEqual( self::BESPOKE_CARRIERS_MAX, count( self::BESPOKE_CARRIERS ), 'shrink-only' );
        $this->assertSame( 0, self::BESPOKE_CARRIERS_MAX, 'cap 0: every carrier block is kit-driven' );
        $this->assertSame( [], self::BESPOKE_CARRIERS );

        // The markers are spelled where the block comments are (none hides in text).
        $spelled = 0;
        foreach ( self::library() as $recipe ) {
            $spelled += substr_count( (string) $recipe['markup'], '"kit":"rule-accent"' );
        }
        $this->assertSame( 2, $spelled, 'the two accent dividers are the only rule-accent markers' );
    }

    public function testMarkedCarriersCarryNoInkOfTheirOwnAndMatchTheirBand(): void
    {
        $own      = [];
        $bands    = [];
        $ownSeen  = 0;
        $bandSeen = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw   = (string) SectionRecipes::rawMarkup( $name );
            $own   = array_merge( $own, self::ownInkProblems( $name, $raw, $ownSeen ) );
            $bands = array_merge( $bands, self::bandProblems( $name, $raw, $bandSeen ) );
        }
        $this->assertSame( 11, $ownSeen, 'every carrier marker is checked for its own ink' );
        $this->assertSame( 11, $bandSeen, 'every carrier marker is checked against its band' );
        $this->assertSame( [], $own, 'a migrated carrier keeps the literal the kit now writes' );
        $this->assertSame( [], $bands, 'a carrier\'s "on" must name the band it is really drawn on' );

        // What the migration kept: the structure the plan names (Task 5 Interfaces, "Dropped literals").
        $first = static fn ( string $recipe, string $block ): array => array_values( array_filter( RuleMigrationTest::blocks( (string) SectionRecipes::rawMarkup( $recipe ) ), static fn ( array $b ): bool => $block === $b[0] ) )[0][1];
        foreach ( [ 'blog-feed', 'icon-values' ] as $recipe ) {
            $d = $first( $recipe, 'divi/divider' );
            $this->assertSame( [ '1ep0co506u' ], $d['modulePreset'] ?? null, "$recipe keeps its divider preset (Spec issue 12)" );
            $this->assertArrayNotHasKey( 'divider', $d, "$recipe: the whole line group is the kit's" );
        }
        $this->assertSame( 'flex-start', $first( 'blog-feed', 'divi/divider' )['module']['decoration']['sizing']['desktop']['value']['alignSelf'] ?? null );
        $t = $first( 'testimonial', 'divi/testimonial' );
        $this->assertSame( [ 'x' => 'visible', 'y' => 'visible' ], $t['module']['decoration']['overflow']['desktop']['value'] ?? null );
        $this->assertSame( 'block', $t['module']['decoration']['layout']['desktop']['value']['display'] ?? null );
        $this->assertSame( '{{aied:color:text}}', $t['author']['decoration']['font']['font']['desktop']['value']['color'] ?? null, 'the testimonial keeps its own text inks' );
        foreach ( range( 0, 3 ) as $i ) {
            $b = array_values( array_filter( RuleMigrationTest::blocks( (string) SectionRecipes::rawMarkup( 'blurb-grid' ) ), static fn ( array $x ): bool => 'divi/blurb' === $x[0] ) )[ $i ][1];
            $this->assertSame( '32px', $b['imageIcon']['decoration']['sizing']['desktop']['value']['iconFontSize'] ?? null, "blurb $i keeps its glyph size (Ruling 5)" );
            $this->assertSame( '16px', $b['imageIcon']['decoration']['spacing']['desktop']['value']['padding']['top'] ?? null, "blurb $i keeps its padding (Ruling 5)" );
            $this->assertStringStartsWith( '&#x', (string) ( $b['imageIcon']['innerContent']['desktop']['value']['icon']['unicode'] ?? '' ), "blurb $i keeps its glyph" );
            $this->assertArrayNotHasKey( 'advanced', $b['imageIcon'], "blurb $i: the glyph colour is the kit's (the emptied group is gone)" );
        }
        foreach ( range( 0, 3 ) as $i ) {
            $c = array_values( array_filter( RuleMigrationTest::blocks( (string) SectionRecipes::rawMarkup( 'stats-counter-band' ) ), static fn ( array $x ): bool => 'divi/number-counter' === $x[0] ) )[ $i ][1];
            $this->assertSame( '20px', $c['module']['decoration']['spacing']['desktop']['value']['padding']['top'] ?? null, "counter $i keeps its padding" );
            $this->assertSame( '100%', $c['module']['decoration']['sizing']['desktop']['value']['width'] ?? null, "counter $i keeps its width" );
            $this->assertSame( 'left', $c['module']['advanced']['text']['text']['desktop']['value']['orientation'] ?? null, "counter $i keeps its text orientation" );
            $this->assertSame( '64px', $c['number']['decoration']['font']['font']['desktop']['value']['size'] ?? null, "counter $i keeps its number" );
        }
    }

    public function testMarkedCarriersKeepOnlyStructureUnderEveryKitAndLanguage(): void
    {
        $problems = [];
        $blocks   = 0;
        foreach ( self::RECIPES as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
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
        $this->assertSame( 11, $blocks, 'the merge trap walks all 11 carrier markers' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked carrier keeps only structure under every kit and language' );
    }

    public function testEveryMigratedRecipeIsServedValidUnderEveryKitAndLanguage(): void
    {
        $checked = 0;
        foreach ( self::RECIPES as $name ) {
            foreach ( DesignLanguages::ids() as $langId ) {
                foreach ( StyleKits::ids() as $kitId ) {
                    $label  = "$name under $langId with $kitId";
                    $markup = SectionRecipes::recipe( $name, $langId, $kitId );
                    $this->assertIsString( $markup, $label );
                    $this->assertStringNotContainsString( '{{aied:', $markup, "$label leaves a token" );
                    $this->assertStringNotContainsString( '"aied"', $markup, "$label leaves a marker" );
                    $this->assertSame( [], KitResolver::leftovers( $markup ), $label );
                    $this->assertSame( [], LanguageResolver::leftovers( $markup ), $label );
                    $result = LayoutGate::check( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' );
                    $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                    $this->assertTrue( $result->isValid(), "$label must pass the layout gate, got: $codes" );
                    ++$checked;
                }
            }
        }
        $this->assertSame( 5 * 10 * 40, $checked );
    }

    public function testRecipesFileIsSmallerThanBeforeTheCarriers(): void
    {
        $file = __DIR__ . '/../wp-plugin/data/section-recipes.json';
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE_CARRIERS, filesize( $file ), 'migrating the carriers shrinks the recipe file (carriers adapter plan, Task 5)' );
        $this->assertLessThan( 700 * 1024, filesize( $file ), 'the 700 KB bound holds' );
    }

    public function testTheMigrationScriptIsIdempotent(): void
    {
        require_once __DIR__ . '/../scripts/migrate-carrier-markers.php';
        $raw = (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' );
        [ $again, $stats ] = \CarrierMarkerMigration::run( $raw );
        $this->assertSame( $raw, $again, 'a second run of scripts/migrate-carrier-markers.php changes nothing' );
        $this->assertSame( 0, $stats['migrated'] );
    }

    public function testCarrierMarkedRecipeProseDoesNotDescribeTheKitLook(): void
    {
        $phrases = array_merge( RuleMigrationTest::RULE_DECIDED_PHRASES, ChipMigrationTest::CHIP_DECIDED_PHRASES, RecipeLibraryTest::CARD_DECIDED_PHRASES );
        $problems = [];
        $seen     = 0;
        foreach ( self::library() as $recipe ) {
            if ( [] === self::marked( (string) $recipe['markup'] ) ) {
                continue;
            }
            ++$seen;
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                foreach ( $phrases as $phrase ) {
                    if ( 1 === preg_match( $phrase, (string) ( $recipe[ $field ] ?? '' ) ) ) {
                        $problems[] = $recipe['name'] . ".$field matches $phrase";
                    }
                }
            }
        }
        $this->assertSame( 5, $seen );
        $this->assertSame( [], $problems, 'the style kit decides the line, the icon badge and the panel: describe the role, not the look' );
        // The old stats-counter-band wording is what the rule phrases catch; the new one passes.
        $this->assertSame( 1, preg_match( RuleMigrationTest::RULE_DECIDED_PHRASES[1], 'each under a thin rule with a short label' ) );
        $this->assertSame( 0, preg_match( RuleMigrationTest::RULE_DECIDED_PHRASES[1], 'each under a rule with a short label' ) );
    }

    // ---- planted cases: each check fails on an unmigrated shape, and passes on the clean shape ----

    /** The old literals of each carrier as the unmigrated recipes carried them, plus one at a breakpoint the kit never writes. @return array<string,mixed> */
    private static function oldInk( string $name ): array
    {
        switch ( $name ) {
            case 'divi/divider':
                return [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:accent}}' ] ], 'tablet' => [ 'value' => [ 'weight' => '6px' ] ] ] ] ] ];
            case 'divi/testimonial':
                return [ 'module' => [ 'decoration' => [
                    'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:surface}}' ] ], 'tablet' => [ 'value' => [ 'color' => '{{aied:color:bg}}' ] ] ],
                    'border'     => [ 'desktop' => [ 'value' => [ 'radius' => [ 'sync' => 'on', 'topLeft' => '10px', 'topRight' => '10px', 'bottomRight' => '10px', 'bottomLeft' => '10px' ], 'styles' => [ 'all' => [ 'width' => '1px', 'color' => '{{aied:color:border}}' ] ] ] ] ],
                ] ] ];
            case 'divi/blurb':
                return [ 'imageIcon' => [
                    'advanced'   => [ 'color' => [ 'desktop' => [ 'value' => '{{aied:color:accent-text}}' ], 'tablet' => [ 'value' => '{{aied:color:text}}' ] ] ],
                    'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:accent}}' ] ] ] ],
                ] ];
            default:
                return [ 'module' => [ 'decoration' => [ 'border' => [
                    'desktop' => [ 'value' => [ 'styles' => [ 'all' => [ 'width' => '0px', 'color' => '{{aied:color:accent-text}}', 'style' => 'solid' ], 'top' => [ 'width' => '1px' ] ] ] ],
                    'tablet'  => [ 'value' => [ 'styles' => [ 'top' => [ 'width' => '5px' ] ] ] ],
                ] ] ] ];
        }
    }

    /** @param callable(string,array<string,mixed>):array<string,mixed> $fn applied to every block that carries a carrier marker */
    private static function mapMarked( string $markup, callable $fn ): string
    {
        return (string) preg_replace_callback(
            '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s',
            static function ( array $m ) use ( $fn ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( ! is_array( $attrs ) || null === self::carrierMarker( $m[1], $attrs ) ) {
                    return $m[0];
                }
                return str_replace( $m[2], RecipeTokens::encodeJson( $fn( $m[1], $attrs ) ), $m[0] );
            },
            $markup
        );
    }

    public function testAnUnmigratedRecipeFailsEveryCheckAndTheCleanShapePasses(): void
    {
        $kits = [ (array) StyleKits::get( 'quiet-solid' ), (array) StyleKits::get( 'raw-frame' ) ];
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        $expectOwn = [
            'divi/divider'        => [ 'divider.advanced.line.desktop.value.color', 'divider.advanced.line.tablet.value.weight' ],
            'divi/testimonial'    => [ 'module.decoration.background.desktop.value.color', 'module.decoration.border.desktop.value.radius.topLeft', 'module.decoration.border.desktop.value.styles.all.width' ],
            'divi/blurb'          => [ 'imageIcon.advanced.color.desktop.value', 'imageIcon.decoration.background.desktop.value.color' ],
            'divi/number-counter' => [ 'module.decoration.border.desktop.value.styles.top.width', 'module.decoration.border.tablet.value.styles.top.width' ],
        ];
        $expectTrap = [
            'divi/divider'        => 'keeps divider.advanced.line.tablet.value.weight that the kit does not write',
            'divi/testimonial'    => 'keeps module.decoration.background.tablet.value.color that the kit does not write',
            'divi/blurb'          => 'keeps imageIcon.advanced.color.tablet.value that the kit does not write',
            'divi/number-counter' => 'keeps module.decoration.border.tablet.value.styles.top.width that the kit does not write',
        ];
        foreach ( [ 'icon-values' => 'divi/divider', 'testimonial' => 'divi/testimonial', 'blurb-grid' => 'divi/blurb', 'stats-counter-band' => 'divi/number-counter' ] as $name => $carrier ) {
            $raw     = (string) SectionRecipes::rawMarkup( $name );
            $count   = count( array_filter( self::marked( $raw ), static fn ( array $b ): bool => $carrier === $b[0] ) );
            $this->assertGreaterThan( 0, $count, "$name is migrated (Task 5)" );
            $planted = self::mapMarked( $raw, static fn ( string $n, array $a ): array => $carrier === $n ? array_replace_recursive( $a, self::oldInk( $n ) ) : $a );
            // (a) own ink.
            $own = implode( "\n", self::ownInkProblems( $name, $planted ) );
            foreach ( $expectOwn[ $carrier ] as $path ) {
                $this->assertStringContainsString( "the migrated block must drop its own $path", $own, "$name $path" );
            }
            // (b) a wrong band is caught.
            $on    = (string) array_values( array_filter( self::marked( $raw ), static fn ( array $b ): bool => $carrier === $b[0] ) )[0][1]['aied']['on'];
            $wrong = 'light' === $on ? 'dark' : 'light';
            $moved = self::mapMarked( $raw, static function ( string $n, array $a ) use ( $wrong ): array {
                $a['aied']['on'] = $wrong;
                return $a;
            } );
            $this->assertStringContainsString( "marker on=$wrong, but the nearest ground makes it $on", implode( "\n", self::bandProblems( $name, $moved ) ), "$name a wrong band" );
            // (c) the merge trap: the tablet leaf the kit never writes survives the merge and is reported.
            foreach ( $kits as $kit ) {
                $this->assertStringContainsString( $expectTrap[ $carrier ], implode( "\n", self::mergeTrapProblems( $name, $planted, KitResolver::resolve( $planted, $kit ), $kit, null ) ), "$name {$kit['id']}" );
            }
            // The clean shape (the shipped recipe) passes all three, kit-only and in a language.
            $this->assertSame( [], self::ownInkProblems( $name, $raw ) );
            $this->assertSame( [], self::bandProblems( $name, $raw ) );
            foreach ( $kits as $kit ) {
                $this->assertSame( [], self::mergeTrapProblems( $name, $raw, KitResolver::resolve( $raw, $kit ), $kit, null ), "$name {$kit['id']} clean" );
                $this->assertSame( [], self::mergeTrapProblems( $name, $raw, (string) SectionRecipes::recipe( $name, 'luxe', (string) $kit['id'] ), $kit, $lang ), "$name {$kit['id']} clean in luxe" );
            }
        }
    }

    public function testTheMergeTrapCatchesStraysAndOverrides(): void
    {
        $kit = (array) StyleKits::get( 'quiet-solid' );
        $sec = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $blk = static fn ( string $name, array $attrs ): string => '<!-- wp:divi/' . $name . ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
        $run = static fn ( string $raw ): string => implode( "\n", self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $kit ), $kit, null ) );

        $blurb   = [ 'imageIcon' => [ 'decoration' => [ 'sizing' => [ 'desktop' => [ 'value' => [ 'iconFontSize' => '32px' ] ] ] ] ], 'builderVersion' => '5.0.0', 'aied' => [ 'kit' => 'chip', 'on' => 'surface' ] ];
        $divider = [ 'modulePreset' => [ 'x' ], 'builderVersion' => '5.2.1', 'aied' => [ 'kit' => 'rule-accent', 'on' => 'light' ] ];
        $this->assertSame( '', $run( $sec( 'surface', $blk( 'blurb', $blurb ) ) ) );
        $this->assertSame( '', $run( $sec( 'bg', $blk( 'divider', $divider ) ) ) );

        // A stray glyph colour on a marked blurb, and a kept line colour on a marked divider (the kit overrides the desktop
        // value; a kept one at another state survives the merge).
        $this->assertStringContainsString( 'keeps imageIcon.advanced.color.tablet.value that the kit does not write', $run( $sec( 'surface', $blk( 'blurb', array_replace_recursive( $blurb, [ 'imageIcon' => [ 'advanced' => [ 'color' => [ 'tablet' => [ 'value' => '#000' ] ] ] ] ] ) ) ) ) );
        $this->assertStringContainsString( 'keeps divider.advanced.line.desktop.hover.color that the kit does not write', $run( $sec( 'bg', $blk( 'divider', array_replace_recursive( $divider, [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'hover' => [ 'color' => '#fff' ] ] ] ] ] ] ) ) ) ) );
        // A kept desktop line colour is the kit's value after the merge: the own-ink check catches it in the raw markup.
        $kept = $sec( 'bg', $blk( 'divider', array_replace_recursive( $divider, [ 'divider' => [ 'advanced' => [ 'line' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:text}}' ] ] ] ] ] ] ) ) );
        $this->assertStringContainsString( 'must drop its own divider.advanced.line.desktop.value.color', implode( "\n", self::ownInkProblems( 'p', $kept ) ) );
        // A resolved block whose value lost the merge, a recipe leaf changed, a leftover marker.
        $raw = $sec( 'surface', $blk( 'blurb', $blurb ) );
        $this->assertStringContainsString( 'is "#123456", not the kit\'s', implode( "\n", self::mergeTrapProblems( 'p', $raw, (string) str_replace( '{{aied:color:accent-text}}', '#123456', KitResolver::resolve( $raw, $kit ) ), $kit, null ) ) );
        $this->assertStringContainsString( 'imageIcon.decoration.sizing.desktop.value.iconFontSize is "40px", not the recipe\'s', implode( "\n", self::mergeTrapProblems( 'p', $raw, (string) str_replace( '"32px"', '"40px"', KitResolver::resolve( $raw, $kit ) ), $kit, null ) ) );
        $this->assertStringContainsString( 'not resolved', implode( "\n", self::mergeTrapProblems( 'p', $raw, $raw, $kit, null ) ) );
        // A language pass compares against the patch resolved in that language.
        $lang = (array) DesignLanguages::get( 'editorial' );
        $this->assertSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, $lang ) );
        $this->assertNotSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, (array) DesignLanguages::get( 'luxe' ) ) );
        // Markers of other carriers are not this test's; a carrier marker without a band throws.
        $this->assertNull( self::carrierMarker( 'divi/group', [ 'aied' => [ 'kit' => 'panel', 'on' => 'light' ] ] ) );
        $this->assertNull( self::carrierMarker( 'divi/divider', [ 'aied' => [ 'kit' => 'rule', 'on' => 'light' ] ] ) );
        $this->assertSame( [ 'rule-strong', 'accent' ], self::carrierMarker( 'divi/number-counter', [ 'aied' => [ 'on' => 'accent', 'kit' => 'rule-strong' ] ] ) );
        $this->expectException( \UnexpectedValueException::class );
        self::carrierMarker( 'divi/blurb', [ 'aied' => [ 'kit' => 'chip' ] ] );
    }
}
