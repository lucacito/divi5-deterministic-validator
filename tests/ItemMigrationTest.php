<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';

/**
 * Recipe-level guards for accordion and toggle items driven by a style kit (markers `item` and `item-rule` on a
 * `divi/accordion` or `divi/toggle`), kept apart from RecipeLibraryTest to keep that file smaller. The item adapter writes
 * the item's own ink (fill, titles, body, icons, frame, shadow), so a migrated block must carry none of its own:
 *
 * - own ink: a marked block holds no colour, border, shadow or background of its own in the groups the patch writes
 *   (the deep merge would hide it today and fight the kit tomorrow: "the migrated block must drop its own");
 * - items: a `divi/accordion-item` under a marked accordion carries no `decoration` at all (an item-level value beats the
 *   parent's);
 * - band: the marker's `on` equals the band the block really sits on (RecipeLibraryTest's band walk), and a rule row sits
 *   on a light or surface band only;
 * - merge trap: under every kit x language, every key of a marked block that the patch does not write is in ITEM_KEEPS
 *   (structure, content and the recipe's type scale), and every key the patch writes holds exactly the patch's value.
 *
 * No recipe carries an item marker before accordion-adapter Task 6, so the recipe tests are vacuous until then; the
 * planted tests below prove each check fails on an unmigrated recipe with a marker added and passes on the clean shape.
 */
class ItemMigrationTest extends TestCase
{
    private const ROLES = [ 'item', 'item-rule' ];

    /** The bands each item role may sit on (KitAdapters' ITEM_BANDS). */
    private const BANDS = [
        'item'      => [ 'light', 'surface', 'accent', 'dark' ],
        'item-rule' => [ 'light', 'surface' ],
    ];

    /** The groups of a marked block whose colours, borders, shadows and backgrounds the item patch decides. */
    private const OWN_INK = [
        '/^module\.decoration\.(border|boxShadow|background)(\.|$)/',
        '/^(openToggle|closedToggle|closedTitle|closedToggleIcon|openToggleIcon)(\.|$)/',
        '/(^|\.)color$/',
    ];

    /**
     * Leaf paths a marked item block may keep that the patch does not write (regexes over dotted paths; any breakpoint and
     * state): the block version, its content (title and answer text), the admin label, the open state, structure (layout,
     * sizing, zIndex, position, animation, margins) and the recipe's own type scale for the title and the answer (family,
     * size, weight, line height and the title's heading level, never a colour). A rule row also keeps its own padding (a
     * structural rhythm, like a panel's); a boxed item's padding is the kit's (a toggle) or none (an accordion: its padding
     * would pad the answer). Anything else fails closed.
     */
    private const ITEM_KEEPS = [
        '/^builderVersion$/',
        '/^(title|content)\.innerContent(\.|$)/',
        '/^module\.meta\.adminLabel(\.|$)/',
        '/^module\.advanced\.open(\.|$)/',
        '/^module\.decoration\.(layout|sizing|zIndex|position|animation)(\.|$)/',
        '/^module\.decoration\.spacing\.[^.]+\.[^.]+\.margin(\.|$)/',
        '/^title\.decoration\.font\.font\.[^.]+\.[^.]+\.(family|size|weight|lineHeight|headingLevel)$/',
        '/^content\.decoration\.bodyFont\.body\.font\.[^.]+\.[^.]+\.(family|size|weight|lineHeight)$/',
    ];

    /** ITEM_KEEPS for a rule row only. */
    private const RULE_KEEPS = [ '/^module\.decoration\.spacing\.[^.]+\.[^.]+\.padding(\.|$)/' ];

    private const BLOCK = '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s';

    /**
     * [role, on] of an item marker in $attrs, or null (no marker, or a card or button role). An item marker without a string
     * `on` throws: the resolver would default it to `light`, and a recipe item must say where it sits.
     *
     * @param array<string,mixed> $attrs
     * @return array{0:string,1:string}|null
     */
    public static function itemMarker( array $attrs ): ?array
    {
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || ! in_array( $m['kit'] ?? null, self::ROLES, true ) ) {
            return null;
        }
        if ( ! is_string( $m['on'] ?? null ) || '' === $m['on'] ) {
            throw new \UnexpectedValueException( 'an ' . $m['kit'] . ' marker without "on": a recipe item must name its band, never default to light' );
        }
        return [ (string) $m['kit'], $m['on'] ];
    }

    /** Opening block comments of $markup in document order: list of [name, attrs, index among that name]. @return list<array{0:string,1:array<string,mixed>,2:int}> */
    private static function blocks( string $markup ): array
    {
        preg_match_all( self::BLOCK, $markup, $all, PREG_SET_ORDER );
        $out  = [];
        $seen = [];
        foreach ( $all as $m ) {
            $seen[ $m[1] ] = ( $seen[ $m[1] ] ?? -1 ) + 1;
            $attrs         = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : [];
            $out[]         = [ $m[1], is_array( $attrs ) ? $attrs : [], $seen[ $m[1] ] ];
        }
        return $out;
    }

    /** Dotted path => value of every leaf of $node (an empty array is a leaf). @return array<string,mixed> */
    private static function leaves( array $node, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            $path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && [] !== $v ) {
                $out += self::leaves( $v, $path );
            } else {
                $out[ $path ] = $v;
            }
        }
        return $out;
    }

    private static function matchesAny( string $path, array $patterns ): bool
    {
        foreach ( $patterns as $re ) {
            if ( 1 === preg_match( $re, $path ) ) {
                return true;
            }
        }
        return false;
    }

    /** How many item markers the block comments of $markup carry (parsed, so key order does not matter). */
    private static function markerCount( string $markup ): int
    {
        return count( array_filter( self::blocks( $markup ), static fn ( array $b ): bool => null !== self::itemMarker( $b[1] ) ) );
    }

    /**
     * (a) and (b): what the RAW markup's marked item blocks still carry of their own ([] = fine): an own-ink leaf on a
     * marked accordion or toggle, and any `decoration` on a `divi/accordion-item` under a marked accordion.
     *
     * @return list<string>
     */
    public static function ownInkProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out  = [];
        $root = ( new \Divi5Validator\BlockParser() )->parse( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' )->root();
        $seen = [];
        $walk = static function ( \Divi5Validator\Block $b, bool $underMarked ) use ( &$walk, &$out, &$seen, &$checked, $label ): void {
            $name          = $b->name();
            $seen[ $name ] = ( $seen[ $name ] ?? -1 ) + 1;
            $where         = "$label $name#{$seen[ $name ]}";
            $marker        = self::itemMarker( $b->attrs() );
            if ( null !== $marker ) {
                ++$checked;
                foreach ( array_keys( self::leaves( $b->attrs() ) ) as $path ) {
                    if ( ! str_starts_with( $path, 'aied.' ) && self::matchesAny( $path, self::OWN_INK ) ) {
                        $out[] = "$where ({$marker[0]} on {$marker[1]}): the migrated block must drop its own $path";
                    }
                }
            }
            if ( $underMarked && 'divi/accordion-item' === $name ) {
                foreach ( array_keys( self::leaves( $b->attrs() ) ) as $path ) {
                    if ( 1 === preg_match( '/(^|\.)decoration(\.|$)|^(openToggle|closedToggle|closedTitle|closedToggleIcon|openToggleIcon)(\.|$)/', $path ) ) {
                        $out[] = "$where: an item under a marked accordion carries $path (an item-level value beats the parent's)";
                    }
                }
            }
            $marked = null !== $marker && 'divi/accordion' === $name;
            foreach ( $b->children() as $child ) {
                $walk( $child, $underMarked || $marked );
            }
        };
        $walk( $root, false );
        return $out;
    }

    /**
     * (c): every item marker's `on` must equal the band its nearest ground makes (RecipeLibraryTest's band walk), and that
     * band must be one the role may sit on (a rule row: light or surface only).
     *
     * @return list<string>
     */
    public static function bandProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( RecipeLibraryTest::markerBands( $markup, static fn ( array $a ): ?array => self::itemMarker( $a ) ) as $key => [ $marker, $band ] ) {
            ++$checked;
            [ $role, $on ] = $marker;
            if ( $on !== $band ) {
                $out[] = "$label $key: $role marker on=$on, but the nearest ground makes it $band";
            }
            if ( ! in_array( $band, self::BANDS[ $role ], true ) ) {
                $out[] = "$label $key: $role on a $band band (it sits on " . implode( '|', self::BANDS[ $role ] ) . ' only)';
            }
        }
        return $out;
    }

    /**
     * (d) the merge trap: for every block that carries an item marker in $raw, the same block (name and index) in $resolved
     * (after the kit $kit and, when given, the language $lang): every leaf the patch writes holds exactly the patch's value
     * (the patch resolved the same way), and every other leaf matches ITEM_KEEPS (plus RULE_KEEPS for a rule row).
     *
     * @param array<string,mixed>      $kit
     * @param array<string,mixed>|null $lang
     * @return list<string>
     */
    public static function mergeTrapProblems( string $label, string $raw, string $resolved, array $kit, ?array $lang, int &$checked = 0 ): array
    {
        $out   = [];
        $after = [];
        foreach ( self::blocks( $resolved ) as [ $name, $attrs, $i ] ) {
            $after[ "$name#$i" ] = $attrs;
        }
        foreach ( self::blocks( $raw ) as [ $name, $attrs, $i ] ) {
            $marker = self::itemMarker( $attrs );
            if ( null === $marker ) {
                continue;
            }
            ++$checked;
            [ $role, $on ] = $marker;
            $where = "$label $name#$i ($role on $on)";
            $got   = $after[ "$name#$i" ] ?? null;
            $bare  = KitResolver::resolve( '<!-- wp:' . $name . ' ' . json_encode( [ 'aied' => [ 'kit' => $role, 'on' => $on ] ] ) . ' /-->', $kit );
            if ( null !== $lang ) {
                $bare = RecipeTokens::resolve( $bare, $lang );
            }
            $expect = self::blocks( $bare )[0][1] ?? null;
            if ( ! is_array( $got ) || ! is_array( $expect ) || [] === $expect || isset( $got['aied'] ) ) {
                $out[] = "$where: not resolved";
                continue;
            }
            $want  = self::leaves( $expect );
            $keeps = 'item-rule' === $role ? array_merge( self::ITEM_KEEPS, self::RULE_KEEPS ) : self::ITEM_KEEPS;
            foreach ( self::leaves( $got ) as $path => $value ) {
                if ( array_key_exists( $path, $want ) ) {
                    if ( $value !== $want[ $path ] ) {
                        $out[] = "$where: $path is " . json_encode( $value, JSON_UNESCAPED_SLASHES ) . ', not the kit\'s ' . json_encode( $want[ $path ], JSON_UNESCAPED_SLASHES );
                    }
                } elseif ( ! self::matchesAny( $path, $keeps ) ) {
                    $out[] = "$where: keeps $path that the kit does not write";
                }
            }
            foreach ( array_diff_key( $want, self::leaves( $got ) ) as $path => $value ) {
                $out[] = "$where: lacks the kit's $path";
            }
        }
        return $out;
    }

    /** The item markers of the recipe FILE: [parsed from block comments, spelled `"kit":"item"` / `"kit":"item-rule"`]. @return array{0:int,1:int} */
    private static function markersInFile(): array
    {
        $parsed  = 0;
        $spelled = 0;
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            $markup   = (string) $recipe['markup'];
            $parsed  += self::markerCount( $markup );
            $spelled += substr_count( $markup, '"kit":"item"' ) + substr_count( $markup, '"kit":"item-rule"' );
        }
        return [ $parsed, $spelled ];
    }

    public function testMarkedItemsCarryNoInkOfTheirOwnAndMatchTheirBand(): void
    {
        [ $parsed, $spelled ] = self::markersInFile();
        $this->assertSame( $spelled, $parsed, 'every "kit":"item|item-rule" in the file is a top-level marker of a block comment' );
        $own      = [];
        $bands    = [];
        $ownSeen  = 0;
        $bandSeen = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw   = (string) SectionRecipes::rawMarkup( $name );
            $own   = array_merge( $own, self::ownInkProblems( $name, $raw, $ownSeen ) );
            $bands = array_merge( $bands, self::bandProblems( $name, $raw, $bandSeen ) );
        }
        $this->assertSame( $parsed, $ownSeen, 'every item marker in the file is checked for its own ink' );
        $this->assertSame( $parsed, $bandSeen, 'every item marker in the file is checked against its band' );
        $this->assertSame( [], $own, 'a migrated item block keeps its own colour, border, shadow or background, or an item carries decoration' );
        $this->assertSame( [], $bands, 'an item\'s "on" must name the band it really sits on, or the kit picks the wrong fill' );
    }

    public function testMarkedItemsKeepOnlyStructureUnderEveryKitAndLanguage(): void
    {
        $problems = [];
        $blocks   = 0;
        foreach ( SectionRecipes::names() as $name ) {
            $raw = (string) SectionRecipes::rawMarkup( $name );
            if ( 0 === self::markerCount( $raw ) ) {
                continue;
            }
            foreach ( StyleKits::ids() as $n => $kitId ) {
                $kit  = (array) StyleKits::get( $kitId );
                $seen = 0;
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
        $this->assertSame( self::markersInFile()[0], $blocks, 'the merge trap checks every item marker in the file' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked item keeps only structure, content and its type scale: its own ink survives the kit\'s deep merge' );
    }

    // ---- planted cases: each check fails on an unmigrated recipe with a marker added, and passes on the clean shape ----

    /** $markup with an item marker of $role on $on added to every block of $carrier. */
    private static function markEvery( string $markup, string $carrier, string $role, string $on ): string
    {
        return (string) preg_replace_callback(
            '/(<!--\s+wp:(' . preg_quote( $carrier, '/' ) . ')\s+)(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})(\s+\/?-->)/s',
            static function ( array $m ) use ( $role, $on ): string {
                $attrs         = json_decode( $m[3], true );
                $attrs['aied'] = [ 'kit' => $role, 'on' => $on ];
                return $m[1] . RecipeTokens::encodeJson( $attrs ) . $m[4];
            },
            $markup
        );
    }

    /**
     * $markup with the own ink of every marked item block removed: every leaf that is not in ITEM_KEEPS (plus RULE_KEEPS for
     * a rule row) and not the marker is dropped (the migration Task 6 makes by hand).
     */
    private static function migrate( string $markup ): string
    {
        return (string) preg_replace_callback(
            self::BLOCK,
            static function ( array $m ): string {
                $attrs  = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                $marker = is_array( $attrs ) ? self::itemMarker( $attrs ) : null;
                if ( null === $marker ) {
                    return $m[0];
                }
                $keeps = 'item-rule' === $marker[0] ? array_merge( self::ITEM_KEEPS, self::RULE_KEEPS ) : self::ITEM_KEEPS;
                $clean = [];
                foreach ( self::leaves( $attrs ) as $path => $value ) {
                    if ( str_starts_with( $path, 'aied.' ) || self::matchesAny( $path, $keeps ) ) {
                        $ref = &$clean;
                        foreach ( explode( '.', $path ) as $k ) {
                            $ref = &$ref[ $k ];
                        }
                        $ref = $value;
                        unset( $ref );
                    }
                }
                return str_replace( $m[2], RecipeTokens::encodeJson( $clean ), $m[0] );
            },
            $markup
        );
    }

    /** The three recipes with items, each with its carrier, role and the band its section really paints. */
    private const PLANTS = [
        'faq-accordion'            => [ 'divi/accordion', 'item' ],
        'features-accordion-image' => [ 'divi/accordion', 'item-rule' ],
        'faq-toggle-list'          => [ 'divi/toggle', 'item-rule' ],
    ];

    /** The band the planted recipe's items really sit on (by the shared walk, read from a marker that names nothing yet). */
    private static function realBand( string $raw, string $carrier ): string
    {
        $bands = RecipeLibraryTest::markerBands( self::markEvery( $raw, $carrier, 'item', 'x' ), static fn ( array $a ): ?array => self::itemMarker( $a ) );
        return (string) $bands[ $carrier . '#0' ][1];
    }

    public function testAnUnmigratedRecipeWithAMarkerFailsEveryCheckAndTheCleanShapePasses(): void
    {
        $kits = [ (array) StyleKits::get( 'soft-solid' ), (array) StyleKits::get( 'underline-link' ), (array) StyleKits::get( 'chunky-pill-offset' ) ];
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        foreach ( self::PLANTS as $name => [ $carrier, $role ] ) {
            $raw  = (string) SectionRecipes::rawMarkup( $name );
            $this->assertSame( 0, self::markerCount( $raw ), "$name is unmigrated in this task (Task 6 migrates it)" );
            $band = self::realBand( $raw, $carrier );
            $this->assertContains( $band, self::BANDS[ $role ], "$name: its items sit on a band the role allows" );

            $planted = self::markEvery( $raw, $carrier, $role, $band );
            $count   = self::markerCount( $planted );
            $this->assertGreaterThan( 0, $count );
            // (a) own ink: the recipe's frame, fills, title colour and icon colours are all reported.
            $seen = 0;
            $own  = implode( "\n", self::ownInkProblems( $name, $planted, $seen ) );
            $this->assertSame( $count, $seen );
            $this->assertStringContainsString( 'the migrated block must drop its own module.decoration.border.desktop.value.styles', $own );
            $this->assertStringContainsString( 'its own closedToggle.decoration.background.desktop.value.color', $own );
            $this->assertStringContainsString( 'its own closedToggleIcon.decoration.icon.desktop.value.color', $own );
            $this->assertStringContainsString( 'its own title.decoration.font.font.desktop.value.color', $own );
            $this->assertStringContainsString( 'its own content.decoration.bodyFont.body.font.desktop.value.color', $own );
            // (c) the band matches; a wrong band is caught.
            $this->assertSame( [], self::bandProblems( $name, $planted ) );
            $wrong = 'light' === $band ? 'surface' : 'light';
            $this->assertStringContainsString( "marker on=$wrong, but the nearest ground makes it $band", implode( "\n", self::bandProblems( $name, self::markEvery( $raw, $carrier, $role, $wrong ) ) ) );
            // (d) the merge trap: the recipe's title colour survives the merge under every kit; it is reported.
            foreach ( $kits as $kit ) {
                $trap = implode( "\n", self::mergeTrapProblems( $name, $planted, KitResolver::resolve( $planted, $kit ), $kit, null ) );
                $this->assertStringContainsString( 'keeps title.decoration.font.font.desktop.value.color that the kit does not write', $trap, "$name {$kit['id']}" );
            }

            // The clean shape: the same recipe with its own ink removed passes all four checks, kit-only and in a language.
            $clean = self::migrate( $planted );
            $this->assertSame( $count, self::markerCount( $clean ) );
            $this->assertSame( [], self::ownInkProblems( $name, $clean ) );
            $this->assertSame( [], self::bandProblems( $name, $clean ) );
            foreach ( $kits as $kit ) {
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, KitResolver::resolve( $clean, $kit ), $kit, null ), "$name {$kit['id']} clean" );
                $this->assertSame( [], self::mergeTrapProblems( $name, $clean, RecipeTokens::resolve( KitResolver::resolve( $clean, $kit ), $lang ), $kit, $lang ), "$name {$kit['id']} clean in luxe" );
            }
            // The clean shape kept its content and type scale (only ink was dropped).
            $first = self::blocks( $clean );
            $kept  = array_values( array_filter( $first, static fn ( array $b ): bool => $b[0] === $carrier ) )[0][1];
            $this->assertArrayHasKey( 'builderVersion', $kept, "$name keeps its version" );
            if ( 'divi/toggle' === $carrier ) {
                $this->assertArrayHasKey( 'innerContent', (array) ( $kept['title'] ?? [] ), "$name keeps its title text" );
            }
            $this->assertNotEmpty( $kept['title']['decoration']['font']['font']['desktop']['value']['family'] ?? null, "$name keeps its title family" );
            $this->assertSame( '{{aied:font:body}}', $kept['content']['decoration']['bodyFont']['body']['font']['desktop']['value']['family'] ?? null, "$name keeps its body family" );
        }
    }

    public function testTheMergeTrapCatchesStraysAndOverrides(): void
    {
        $kit  = (array) StyleKits::get( 'soft-solid' );
        $sec  = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $tog  = static fn ( array $attrs ): string => '<!-- wp:divi/toggle ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
        $run  = static fn ( string $raw, ?array $k = null ): string => implode( "\n", self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $k ?? $kit ), $k ?? $kit, null ) );
        $item = [ 'kit' => 'item', 'on' => 'light' ];
        $rule = [ 'kit' => 'item-rule', 'on' => 'light' ];

        // Clean: content, open, structure, margins and the type scale.
        $clean = [
            'title'   => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Q' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'headingLevel' => 'h3', 'family' => '{{aied:font:display}}', 'size' => '20px', 'weight' => '600', 'lineHeight' => '1.3em' ] ], 'phone' => [ 'value' => [ 'size' => '18px' ] ] ] ] ] ],
            'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>A</p>' ] ] ],
            'module'  => [ 'advanced' => [ 'open' => [ 'desktop' => [ 'value' => 'on' ] ] ], 'meta' => [ 'adminLabel' => [ 'desktop' => [ 'value' => 'FAQ' ] ] ], 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'bottom' => '12px' ] ] ] ], 'sizing' => [ 'desktop' => [ 'value' => [ 'maxWidth' => '720px' ] ] ] ] ],
            'builderVersion' => '5.15.1',
        ];
        $this->assertSame( '', $run( $sec( 'bg', $tog( $clean + [ 'aied' => $item ] ) ) ) );
        $this->assertSame( '', $run( $sec( 'bg', $tog( array_replace_recursive( $clean, [ 'module' => [ 'decoration' => [ 'spacing' => [ 'phone' => [ 'value' => [ 'padding' => [ 'top' => '20px' ] ] ] ] ] ] ] ) + [ 'aied' => $rule ] ) ) ), 'a rule row keeps its own padding' );

        // A recipe's padding on a boxed item (the kit's on a toggle) at another breakpoint survives the merge: caught.
        $padded = array_replace_recursive( $clean, [ 'module' => [ 'decoration' => [ 'spacing' => [ 'phone' => [ 'value' => [ 'padding' => [ 'top' => '20px' ] ] ] ] ] ] ] );
        $this->assertStringContainsString( 'keeps module.decoration.spacing.phone.value.padding.top', $run( $sec( 'bg', $tog( $padded + [ 'aied' => $item ] ) ) ) );
        // Colours at another breakpoint, a hover fill, a title colour, a tablet frame, a filter: each is a stray.
        $strays = [
            'closedTitle.decoration.font.font.phone.value.color'           => [ 'closedTitle' => [ 'decoration' => [ 'font' => [ 'font' => [ 'phone' => [ 'value' => [ 'color' => '#fff' ] ] ] ] ] ] ],
            'openToggle.decoration.background.desktop.hover.color'          => [ 'openToggle' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'hover' => [ 'color' => '#fff' ] ] ] ] ] ],
            'title.decoration.font.font.desktop.value.color'                => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:accent}}' ] ] ] ] ] ] ],
            'module.decoration.border.tablet.value.styles.all.width'        => [ 'module' => [ 'decoration' => [ 'border' => [ 'tablet' => [ 'value' => [ 'styles' => [ 'all' => [ 'width' => '3px' ] ] ] ] ] ] ] ],
            'module.decoration.filters.desktop.value.blur'                  => [ 'module' => [ 'decoration' => [ 'filters' => [ 'desktop' => [ 'value' => [ 'blur' => '2px' ] ] ] ] ] ],
            'content.decoration.bodyFont.body.font.desktop.value.letterSpacing' => [ 'content' => [ 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [ 'desktop' => [ 'value' => [ 'letterSpacing' => '1px' ] ] ] ] ] ] ] ],
            'module.decoration.boxShadow.desktop.value.blur'                => [ 'module' => [ 'decoration' => [ 'boxShadow' => [ 'desktop' => [ 'value' => [ 'blur' => '40px' ] ] ] ] ] ],
        ];
        foreach ( $strays as $path => $attrs ) {
            $this->assertStringContainsString( "keeps $path that the kit does not write", $run( $sec( 'bg', $tog( array_replace_recursive( $clean, $attrs ) + [ 'aied' => $item ] ) ) ), $path );
        }
        // A rule row's shadow (the patch writes none) is a stray; a boxed item's shadow under a no-shadow kit too.
        $this->assertStringContainsString( 'keeps module.decoration.boxShadow.desktop.value.blur', $run( $sec( 'bg', $tog( array_replace_recursive( $clean, $strays['module.decoration.boxShadow.desktop.value.blur'] ) + [ 'aied' => $rule ] ) ) ) );
        // A resolved block whose patched value differs (as if the merge lost) and a block that was never resolved.
        $raw      = $sec( 'bg', $tog( $clean + [ 'aied' => $item ] ) );
        $resolved = str_replace( '"openToggle":{', '"openToggle":{"x":1,', KitResolver::resolve( $raw, $kit ) );
        $this->assertSame( [], array_filter( self::mergeTrapProblems( 'p', $raw, KitResolver::resolve( $raw, $kit ), $kit, null ) ) );
        $this->assertStringContainsString( 'keeps openToggle.x', implode( "\n", self::mergeTrapProblems( 'p', $raw, $resolved, $kit, null ) ) );
        $changed = preg_replace( '/"closedTitle":\{"decoration":\{"font":\{"font":\{"desktop":\{"value":\{"color":"[^"]*"/', '"closedTitle":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"#123456"', KitResolver::resolve( $raw, $kit ) );
        $this->assertStringContainsString( 'closedTitle.decoration.font.font.desktop.value.color is "#123456", not the kit\'s', implode( "\n", self::mergeTrapProblems( 'p', $raw, (string) $changed, $kit, null ) ) );
        $this->assertStringContainsString( 'not resolved', implode( "\n", self::mergeTrapProblems( 'p', $raw, $raw, $kit, null ) ) );
        // A language pass compares against the patch resolved in that language.
        $lang = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $lang );
        $this->assertSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, $lang ) );
        $this->assertNotSame( [], self::mergeTrapProblems( 'p', $raw, RecipeTokens::resolve( KitResolver::resolve( $raw, $kit ), $lang ), $kit, (array) DesignLanguages::get( 'luxe' ) ), 'another language\'s colours are not the kit\'s' );
    }

    public function testAnItemUnderAMarkedAccordionMayCarryNoDecoration(): void
    {
        $acc   = static fn ( string $attrs, string $items ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:bg}}"}}}}}} --><!-- wp:divi/accordion ' . $attrs . ' -->' . $items . '<!-- /wp:divi/accordion --><!-- /wp:divi/section -->';
        $plain = '<!-- wp:divi/accordion-item {"title":{"innerContent":{"desktop":{"value":"Q"}}},"content":[],"module":{"advanced":{"open":{"desktop":{"value":"on"}}}},"builderVersion":"5.8.0"} /-->';
        $this->assertSame( [], self::ownInkProblems( 'p', $acc( '{"aied":{"kit":"item","on":"light"}}', $plain ) ) );
        foreach ( [
            'module.decoration.border.desktop.value.styles.all.color' => '"module":{"decoration":{"border":{"desktop":{"value":{"styles":{"all":{"color":"#000"}}}}}}}',
            'openToggle.decoration.background.desktop.value.color'    => '"openToggle":{"decoration":{"background":{"desktop":{"value":{"color":"#fff"}}}}}',
            'title.decoration.font.font.desktop.value.size'           => '"title":{"decoration":{"font":{"font":{"desktop":{"value":{"size":"30px"}}}}}}',
            'closedToggleIcon'                                        => '"closedToggleIcon":[]',
        ] as $path => $json ) {
            $item = '<!-- wp:divi/accordion-item {' . $json . '} /-->';
            $this->assertSame(
                [ "p divi/accordion-item#1: an item under a marked accordion carries $path (an item-level value beats the parent's)" ],
                self::ownInkProblems( 'p', $acc( '{"aied":{"kit":"item-rule","on":"light"}}', $plain . $item ) ),
                $path
            );
            // Under an UNMARKED accordion an item may keep its own look (nothing styles it from the parent).
            $this->assertSame( [], self::ownInkProblems( 'p', $acc( '{}', $item ) ), "$path unmarked" );
        }
    }

    public function testTheItemBandWalkCatchesWhatItShould(): void
    {
        $sec = static fn ( string $colour, string $inner ): string => '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:' . $colour . '}}"}}}}}} -->' . $inner . '<!-- /wp:divi/section -->';
        $acc = static fn ( string $role, string $on ): string => '<!-- wp:divi/accordion {"aied":{"on":"' . $on . '","kit":"' . $role . '"}} --><!-- /wp:divi/accordion -->';
        $tog = static fn ( string $role, string $on ): string => '<!-- wp:divi/toggle {"aied":{"kit":"' . $role . '","on":"' . $on . '"}} /-->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $acc( 'item', 'light' ) ) ), 'key order does not matter' );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'surface', $tog( 'item-rule', 'surface' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'accent', $acc( 'item', 'accent' ) ) ) );
        $this->assertSame( [ 'p divi/accordion#0: item marker on=light, but the nearest ground makes it surface' ], self::bandProblems( 'p', $sec( 'surface', $acc( 'item', 'light' ) ) ) );
        // A rule row on an accent or dark band fails even when `on` agrees; a boxed item there is fine.
        $this->assertSame( [ 'p divi/toggle#0: item-rule on a accent band (it sits on light|surface only)' ], self::bandProblems( 'p', $sec( 'accent', $tog( 'item-rule', 'accent' ) ) ) );
        $this->assertCount( 1, self::bandProblems( 'p', $sec( 'overlay', $tog( 'item-rule', 'dark' ) ) ) );
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'overlay', $tog( 'item', 'dark' ) ) ) );
        // No ground anywhere fails loudly (never defaults to light); an item inside a marked card sits on its fill.
        $this->assertSame( [ 'p divi/toggle#0: item marker on=light, but the nearest ground makes it none', 'p divi/toggle#0: item on a none band (it sits on light|surface|accent|dark only)' ], self::bandProblems( 'p', $tog( 'item', 'light' ) ) );
        $card = '<!-- wp:divi/group {"aied":{"kit":"panel","on":"light"}} -->' . $tog( 'item-rule', 'surface' ) . '<!-- /wp:divi/group -->';
        $this->assertSame( [], self::bandProblems( 'p', $sec( 'bg', $card ) ), 'a panel on a light band is filled surface' );
        // A marker without a band throws; a card or button marker is no item marker.
        $this->assertNull( self::itemMarker( [ 'aied' => [ 'kit' => 'card', 'on' => 'light' ] ] ) );
        $this->assertNull( self::itemMarker( [ 'aied' => [ 'kit' => 'control' ] ] ) );
        $this->assertSame( 1, self::markerCount( $sec( 'bg', $tog( 'item', 'light' ) . '<!-- wp:divi/group {"aied":{"kit":"card","on":"light"}} --><!-- /wp:divi/group -->' ) ) );
        foreach ( [ [ 'kit' => 'item' ], [ 'kit' => 'item-rule', 'on' => '' ], [ 'kit' => 'item', 'on' => 1 ] ] as $marker ) {
            try {
                self::itemMarker( [ 'aied' => $marker ] );
                $this->fail( 'an item marker without a band must fail: ' . json_encode( $marker ) );
            } catch ( \UnexpectedValueException $e ) {
                $this->assertStringContainsString( 'without "on"', $e->getMessage() );
            }
        }
    }
}
