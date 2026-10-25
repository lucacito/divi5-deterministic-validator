<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\RhythmAudit;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/RhythmAudit.php';

/**
 * Recipe-level guards for the sections whose vertical air a design language decides (marker `"aied":{"rhythm":"section|open|tight"}`
 * on a `divi/section`; rhythm adapter, plan 2026-10-24, Task 5), kept apart from RecipeLibraryTest. The language writes the
 * top and bottom padding (and `syncVertical`) at desktop, tablet and phone, so a migrated section carries none of its own
 * (Ruling 4):
 *
 * - pinned: exactly the 46 sections of MIGRATED carry a rhythm marker, with that role; nothing is bespoke (cap 0) and every
 *   other section is one of RhythmAudit::EXEMPTIONS, still carrying its own literal padding (or none);
 * - own padding: a marked section keeps no `top`, `bottom` or `syncVertical` under `module.decoration.spacing`;
 * - merge trap: served under every language (with the language's own kit), a marked section holds exactly the language's
 *   patch under `module.decoration.spacing` and, for every other leaf, the raw block's value after the kit and token steps,
 *   and no marker;
 * - dividers: a section divider never grows past the padding of its edge under any language;
 * - served: every recipe is valid through LayoutGate under every language and leaves no marker, token or leftover;
 * - prose: a rhythm-marked recipe names no padding number, and only an `open` one may promise tall or generous padding.
 *
 * The recipe file is migrated ONLY by scripts/migrate-rhythm-markers.php (`--check` proves it is idempotent: this test runs
 * it). MIGRATED is a literal (generated once from RhythmAudit at 3eb1c94, never read from the file at test time).
 */
class RhythmMigrationTest extends TestCase
{
    /** The spacing group the language decides (LanguageAdapters::RHYTHM_PATHS['divi/section']). */
    private const SPACING = 'module.decoration.spacing';

    /** The three keys the language decides, at any breakpoint of the spacing group. */
    private const RHYTHM_KEYS = '(top|bottom|syncVertical)';

    /** Sections deliberately NOT driven by the language although they would classify: SHRINK-ONLY and EMPTY (cap 0). */
    private const BESPOKE_RHYTHM = [];

    private const BESPOKE_RHYTHM_MAX = 0;

    /** The file size before the rhythm markers (3eb1c94): the migration must shrink the file. */
    private const SIZE_BEFORE_RHYTHM = 662117;

    /** A padding number in prose: "120px top and bottom padding", "a padding of 80". Clause-bounded (`.` or `;`). */
    private const PADDING_NUMBER = '/\b\d+\s?px\b[^.;]*\bpadding\b|\bpadding of \d+/i';

    /** A spacing look only an `open` section may promise. */
    private const OPEN_ONLY_PADDING = '/\b(tall|generous) padding\b/i';

    /**
     * The 25 exemptions still say what they said before the migration (RhythmAudit's `raw` per breakpoint, desktop/tablet/phone:
     * the value when top = bottom, `top~bottom` otherwise, `-` for none).
     */
    private const EXEMPT_PADDING = [
        'hero-cta|divi/section|0'                 => '8%/-/-',
        'section-intro|divi/section|0'            => '16px~-/-/-',
        'card-grid-3|divi/section|0'              => '-/-/-',
        'card-grid-4|divi/section|0'              => '8vw/-/-',
        'blurb-grid|divi/section|0'               => '-/-/0px',
        'icon-features|divi/section|0'            => '-/-/0px',
        'split-image-text|divi/section|0'         => '-/-/-',
        'contact-form|divi/section|0'             => '-/-/-',
        'slider|divi/section|0'                   => '-/-/-',
        'image-gallery|divi/section|0'            => '0px~100px/-/-',
        'image-carousel|divi/section|0'           => '-/-/-',
        'testimonial|divi/section|0'              => '-/-/0px',
        'newsletter-social|divi/section|0'        => '-/-/-',
        'stats-counter|divi/section|0'            => '40px/-/-',
        'blog-feed|divi/section|0'                => '100px/-/-',
        'icon-values|divi/section|0'              => '100px/-/-',
        'shop-grid|divi/section|0'                => '-/-/-',
        'hero-image-scrim|divi/section|0'         => '0px/-/-',
        'hero-editorial-minimal|divi/section|0'   => '180px~140px/120px~96px/80px~64px',
        'hero-product-glow|divi/section|0'        => '120px~96px/88px~72px/56px~48px',
        'header-title-band|divi/section|0'        => '72px~64px/56px~48px/40px~36px',
        'header-divider-intro|divi/section|0'     => '112px~168px/88px~128px/64px~88px',
        'header-image-band|divi/section|0'        => '120px~104px/88px~80px/64px~56px',
        'proof-rating-strip|divi/section|0'       => '32px/28px/28px',
        'transition-overlap-panel|divi/section|0' => '0px~112px/0px~88px/0px~64px',
    ];

    /**
     * The 46 rhythm markers: "<recipe>|divi/section|<index>" => role. Pinned as literals: a lost, added or re-roled marker
     * fails here. section 38, open 7, tight 1 (rhythm adapter plan, Task 4's table).
     */
    private const MIGRATED = [
        'hero-pattern-centered|divi/section|0'      => 'open',
        'hero-split-right|divi/section|0'           => 'section',
        'hero-split-left-overlap|divi/section|0'    => 'section',
        'hero-gradient-stats|divi/section|0'        => 'section',
        'hero-bordered-cells|divi/section|0'        => 'section',
        'hero-spec-card|divi/section|0'             => 'section',
        'features-bento|divi/section|0'             => 'section',
        'features-zigzag|divi/section|0'            => 'section',
        'list-icon-dividers|divi/section|0'         => 'section',
        'checklist-two-column|divi/section|0'       => 'section',
        'steps-numbered|divi/section|0'             => 'section',
        'steps-horizontal|divi/section|0'           => 'section',
        'timeline-vertical|divi/section|0'          => 'section',
        'features-accordion-image|divi/section|0'   => 'section',
        'compare-two-panels|divi/section|0'         => 'section',
        'features-carousel-strip|divi/section|0'    => 'section',
        'stats-big-numbers|divi/section|0'          => 'section',
        'proof-logo-wall|divi/section|0'            => 'tight',
        'proof-press-quotes|divi/section|0'         => 'section',
        'stats-counter-band|divi/section|0'         => 'section',
        'stats-circle-counters|divi/section|0'      => 'section',
        'stats-big-captions|divi/section|0'         => 'section',
        'quote-single-large|divi/section|0'         => 'open',
        'testimonials-slider-quote|divi/section|0'  => 'section',
        'testimonials-avatar-grid|divi/section|0'   => 'section',
        'quote-over-image|divi/section|0'           => 'open',
        'pricing-three-tier|divi/section|0'         => 'section',
        'pricing-two-plan-highlight|divi/section|0' => 'section',
        'pricing-comparison-table|divi/section|0'   => 'section',
        'team-grid|divi/section|0'                  => 'section',
        'founder-note|divi/section|0'               => 'open',
        'values-list|divi/section|0'                => 'section',
        'faq-accordion|divi/section|0'              => 'section',
        'faq-two-column|divi/section|0'             => 'section',
        'faq-toggle-list|divi/section|0'            => 'section',
        'story-timeline|divi/section|0'             => 'open',
        'cta-band|divi/section|0'                   => 'section',
        'cta-split-form|divi/section|0'             => 'section',
        'cta-card-over-image|divi/section|0'        => 'open',
        'cta-countdown|divi/section|0'              => 'section',
        'gallery-masonry-style|divi/section|0'      => 'section',
        'portfolio-featured-grid|divi/section|0'    => 'section',
        'before-after-compare|divi/section|0'       => 'section',
        'contact-location-form|divi/section|0'      => 'section',
        'contact-details-form|divi/section|0'       => 'section',
        'transition-wave-band|divi/section|0'       => 'open',
    ];

    private const BLOCK = '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s';

    private const FILE = __DIR__ . '/../wp-plugin/data/section-recipes.json';

    private const IMAGE_BASE = 'https://example.test/images/';

    /**
     * The role of a rhythm marker in $attrs, or null (no marker, or a marker of another kind). A rhythm marker with any key
     * besides `rhythm` throws.
     *
     * @param array<string,mixed> $attrs
     */
    public static function rhythmMarker( array $attrs ): ?string
    {
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || ! array_key_exists( 'rhythm', $m ) ) {
            return null;
        }
        if ( 1 !== count( $m ) || ! is_string( $m['rhythm'] ) ) {
            throw new \UnexpectedValueException( 'a rhythm marker is exactly {"rhythm":"<role>"}: ' . json_encode( $m ) );
        }
        return $m['rhythm'];
    }

    /** The `divi/section` opening comments of $markup in document order: list of [attrs, index]. @return list<array{0:array<string,mixed>,1:int}> */
    public static function sections( string $markup ): array
    {
        preg_match_all( self::BLOCK, $markup, $all, PREG_SET_ORDER );
        $out = [];
        $i   = -1;
        foreach ( $all as $m ) {
            if ( 'divi/section' !== $m[1] ) {
                continue;
            }
            ++$i;
            $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : [];
            $out[] = [ is_array( $attrs ) ? $attrs : [], $i ];
        }
        return $out;
    }

    /** Dotted path => value of every leaf of $node (an empty array is a leaf). @return array<string,mixed> */
    public static function leaves( array $node, string $prefix = '' ): array
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

    private static function isRhythmLeaf( string $path ): bool
    {
        return 1 === preg_match( '/^' . preg_quote( self::SPACING, '/' ) . '\.[^.]+\.[^.]+\.padding\.' . self::RHYTHM_KEYS . '$/', $path );
    }

    private static function isSpacingLeaf( string $path ): bool
    {
        return str_starts_with( $path, self::SPACING . '.' ) || self::SPACING === $path;
    }

    /** The recipes of the file. @return list<array<string,mixed>> */
    private static function library(): array
    {
        return (array) json_decode( (string) file_get_contents( self::FILE ), true );
    }

    /** "<recipe>|divi/section|<index>" => role of every rhythm marker of the recipe file. @return array<string,string> */
    private static function markersInFile(): array
    {
        $out = [];
        foreach ( self::library() as $recipe ) {
            foreach ( self::sections( (string) $recipe['markup'] ) as [ $attrs, $i ] ) {
                $role = self::rhythmMarker( $attrs );
                if ( null !== $role ) {
                    $out[ $recipe['name'] . "|divi/section|$i" ] = $role;
                }
            }
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> every shipped language by id */
    private function languages(): array
    {
        $out = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $lang = DesignLanguages::get( $id );
            $this->assertIsArray( $lang );
            $out[ $id ] = $lang;
        }
        $this->assertCount( 10, $out );
        return $out;
    }

    /** @param array<string,mixed> $lang @return array<string,mixed> */
    private static function kitOf( array $lang ): array
    {
        return (array) StyleKits::get( (string) $lang['kit'] );
    }

    /**
     * What the RAW markup's marked sections still carry of their own ([] = fine): a top, bottom or syncVertical at any
     * breakpoint of the spacing group.
     *
     * @return list<string>
     */
    public static function ownPaddingProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( self::sections( $markup ) as [ $attrs, $i ] ) {
            $role = self::rhythmMarker( $attrs );
            if ( null === $role ) {
                continue;
            }
            ++$checked;
            foreach ( array_keys( self::leaves( $attrs ) ) as $path ) {
                if ( self::isRhythmLeaf( $path ) ) {
                    $out[] = "$label divi/section#$i ($role): the migrated section must drop its own $path";
                }
            }
        }
        return $out;
    }

    /**
     * The merge trap: for every section that carries a rhythm marker in $raw, the same section (by index) in $resolved
     * (served under $lang with $kit): under the spacing group exactly the patch's leaves with the patch's values, every other
     * leaf equal to the raw section's after the kit and token steps (nothing lost, added or changed), and no `aied` key.
     *
     * @param array<string,mixed> $lang
     * @param array<string,mixed> $kit
     * @return list<string>
     */
    public static function mergeTrapProblems( string $label, string $raw, string $resolved, array $lang, array $kit, int &$checked = 0 ): array
    {
        $out   = [];
        $after = [];
        foreach ( self::sections( $resolved ) as [ $attrs, $i ] ) {
            $after[ $i ] = $attrs;
        }
        foreach ( self::sections( $raw ) as [ $attrs, $i ] ) {
            $role = self::rhythmMarker( $attrs );
            if ( null === $role ) {
                continue;
            }
            ++$checked;
            $where = "$label divi/section#$i ($role)";
            $got   = $after[ $i ] ?? null;
            $patch = LanguageAdapters::patch( 'divi/section', 'rhythm', $role, $lang );
            if ( ! is_array( $got ) || ! is_array( $patch ) || isset( $got['aied'] ) ) {
                $out[] = "$where: not resolved";
                continue;
            }
            $bare = $attrs;
            unset( $bare['aied'] );
            $passed = self::sections( RecipeTokens::resolve( KitResolver::resolve( '<!-- wp:divi/section ' . json_encode( $bare, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' -->', $kit ), $lang ) )[0][0] ?? [];
            $want   = self::leaves( $patch );
            $keep   = array_filter( self::leaves( $passed ), static fn ( string $p ): bool => ! self::isRhythmLeaf( $p ), ARRAY_FILTER_USE_KEY );
            $have   = self::leaves( $got );
            foreach ( $have as $path => $value ) {
                if ( array_key_exists( $path, $want ) ) {
                    if ( $value !== $want[ $path ] ) {
                        $out[] = "$where: $path is " . json_encode( $value ) . ', not the language\'s ' . json_encode( $want[ $path ] );
                    }
                } elseif ( self::isSpacingLeaf( $path ) ) {
                    $out[] = "$where: keeps $path under the spacing the language decides";
                } elseif ( ! array_key_exists( $path, $keep ) ) {
                    $out[] = "$where: gained $path";
                } elseif ( $value !== $keep[ $path ] ) {
                    $out[] = "$where: $path is " . json_encode( $value ) . ', not the recipe\'s ' . json_encode( $keep[ $path ] );
                }
            }
            foreach ( array_diff_key( $want + $keep, $have ) as $path => $value ) {
                $out[] = "$where: lacks $path";
            }
        }
        return $out;
    }

    /**
     * A divider taller than its edge's padding crosses the section's content: for each `module.advanced.dividers.{top,bottom}`
     * of $attrs, the height at each breakpoint (desktop, then the tablet and phone overrides, cascading) must be a px value
     * no larger than $space at that breakpoint (top and bottom padding are equal in a rhythm).
     *
     * @param array<string,mixed> $attrs
     * @param array{desktop:int,tablet:int,phone:int} $space
     * @return list<string>
     */
    public static function dividerProblems( string $label, array $attrs, array $space, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( [ 'top', 'bottom' ] as $edge ) {
            $divider = $attrs['module']['advanced']['dividers'][ $edge ] ?? null;
            if ( ! is_array( $divider ) ) {
                continue;
            }
            $height = null;
            foreach ( DesignLanguages::SECTION_SPACE_KEYS as $bp ) {
                $height = $divider[ $bp ]['value']['height'] ?? $height;
                ++$checked;
                if ( ! is_string( $height ) || 1 !== preg_match( '/^(\d+)px$/', $height, $m ) ) {
                    $out[] = "$label: the $edge divider has no px height at $bp: " . json_encode( $height );
                    continue;
                }
                if ( (int) $m[1] > $space[ $bp ] ) {
                    $out[] = "$label: the $edge divider is {$m[1]}px at $bp, over the {$space[ $bp ]}px padding of its edge";
                }
            }
        }
        return $out;
    }

    public function testTheMarkersArePinnedAndNothingIsBespoke(): void
    {
        $pinned = self::MIGRATED;
        $found  = self::markersInFile();
        ksort( $pinned );
        ksort( $found );
        $this->assertSame( $pinned, $found, 'exactly these sections carry a rhythm marker, with this role' );
        $this->assertCount( 46, $found );
        $spelled = array_sum( array_map( static fn ( array $r ): int => substr_count( (string) $r['markup'], '"aied":{"rhythm":' ), self::library() ) );
        $this->assertSame( 46, $spelled, 'every marker is spelled the one way, as a top-level key' );
        $this->assertSame( [ 'open' => 7, 'section' => 38, 'tight' => 1 ], ( static function ( array $c ): array { ksort( $c ); return $c; } )( array_count_values( $found ) ) );
        foreach ( RhythmAudit::sections( self::library() ) as $r ) {
            $key = RhythmAudit::key( $r );
            if ( $r['marked'] ) {
                $this->assertSame( self::MIGRATED[ $key ] ?? null, $r['role'], $key );
                continue;
            }
            $this->assertArrayHasKey( $r['recipe'], RhythmAudit::EXEMPTIONS, "$key is a section with no rhythm marker: give it one (BESPOKE_RHYTHM never grows)" );
            $this->assertNotNull( $r['exempt'], $key );
            $this->assertArrayNotHasKey( $key, self::BESPOKE_RHYTHM );
        }
        $this->assertLessThanOrEqual( self::BESPOKE_RHYTHM_MAX, count( self::BESPOKE_RHYTHM ), 'shrink-only: BESPOKE_RHYTHM can never grow' );
        $this->assertSame( 0, self::BESPOKE_RHYTHM_MAX, 'cap 0: every section is language-driven or a named exemption' );
        $this->assertSame( [], self::BESPOKE_RHYTHM );
    }

    public function testMarkedSectionsCarryNoPaddingOfTheirOwn(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( self::library() as $recipe ) {
            $problems = array_merge( $problems, self::ownPaddingProblems( (string) $recipe['name'], (string) $recipe['markup'], $checked ) );
        }
        $this->assertSame( 46, $checked, 'every rhythm marker is checked for its own padding' );
        $this->assertSame( [], $problems, 'a marked section keeps its own top, bottom or syncVertical (Ruling 4)' );
    }

    public function testMarkedSectionsKeepEverythingElseUnderEveryLanguage(): void
    {
        $problems = [];
        $sections = 0;
        foreach ( self::library() as $recipe ) {
            $name = (string) $recipe['name'];
            $raw  = ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $name ), self::IMAGE_BASE );
            if ( ! str_contains( $raw, '"aied":{"rhythm":' ) ) {
                continue;
            }
            $n = 0;
            foreach ( $this->languages() as $langId => $lang ) {
                $kit    = self::kitOf( $lang );
                $served = SectionRecipes::resolveMarkup( $raw, $lang, $kit );
                $this->assertIsString( $served, "$name under $langId is served" );
                $seen     = 0;
                $problems = array_merge( $problems, self::mergeTrapProblems( "$name/$langId", $raw, $served, $lang, $kit, $seen ) );
                if ( 0 === $n++ ) {
                    $sections += $seen;
                }
            }
        }
        $this->assertSame( 46, $sections, 'the merge trap walks all 46 rhythm markers' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked section keeps everything but the padding the language writes' );
    }

    public function testTheExemptionsAreExactlyTheNamedSections(): void
    {
        $rows = [];
        foreach ( RhythmAudit::sections( self::library() ) as $r ) {
            $rows[ RhythmAudit::key( $r ) ] = $r;
        }
        $this->assertEqualsCanonicalizing(
            array_map( static fn ( string $recipe ): string => "$recipe|divi/section|0", array_keys( RhythmAudit::EXEMPTIONS ) ),
            array_keys( self::EXEMPT_PADDING ),
            'the audit names exactly these 25 sections'
        );
        $byKey = [];
        foreach ( self::library() as $recipe ) {
            foreach ( self::sections( (string) $recipe['markup'] ) as [ $attrs, $i ] ) {
                $byKey[ $recipe['name'] . "|divi/section|$i" ] = $attrs;
            }
        }
        foreach ( self::EXEMPT_PADDING as $key => $padding ) {
            $this->assertArrayHasKey( $key, $byKey, "$key still exists" );
            $this->assertArrayNotHasKey( 'aied', $byKey[ $key ], "$key stays literal: no marker of any kind" );
            $this->assertFalse( $rows[ $key ]['marked'], $key );
            $this->assertNotSame( '', trim( (string) $rows[ $key ]['exempt'] ), "$key has a reason" );
            $this->assertSame( $padding, implode( '/', $rows[ $key ]['raw'] ), "$key still carries its own padding (or none)" );
        }
    }

    public function testDividersFitTheirSectionsPaddingUnderEveryLanguage(): void
    {
        $problems = [];
        $checked  = 0;
        $withDividers = [];
        foreach ( self::library() as $recipe ) {
            foreach ( self::sections( (string) $recipe['markup'] ) as [ $attrs, $i ] ) {
                $role = self::rhythmMarker( $attrs );
                if ( null === $role || ! isset( $attrs['module']['advanced']['dividers'] ) ) {
                    continue;
                }
                $withDividers[] = $recipe['name'];
                foreach ( $this->languages() as $langId => $lang ) {
                    $space = DesignLanguages::sectionSpace( $lang, $role );
                    $this->assertIsArray( $space );
                    $problems = array_merge( $problems, self::dividerProblems( "{$recipe['name']}/$langId ($role)", $attrs, $space, $checked ) );
                }
            }
        }
        $this->assertSame( [ 'transition-wave-band' ], $withDividers, 'the one marked section with dividers (header-divider-intro is exempt)' );
        $this->assertSame( 2 * 3 * 10, $checked, 'two edges, three breakpoints, ten languages' );
        $this->assertSame( [], $problems );
        // Planted: a 90px divider does not fit brutalist-flat's open band (80/64/40).
        $space = DesignLanguages::sectionSpace( (array) DesignLanguages::get( 'brutalist-flat' ), 'open' );
        $this->assertSame( [ 'desktop' => 80, 'tablet' => 64, 'phone' => 40 ], $space );
        $planted = [ 'module' => [ 'advanced' => [ 'dividers' => [ 'top' => [ 'desktop' => [ 'value' => [ 'style' => 'wave', 'height' => '90px' ] ] ] ] ] ] ];
        $this->assertSame(
            [ 'p: the top divider is 90px at desktop, over the 80px padding of its edge', 'p: the top divider is 90px at tablet, over the 64px padding of its edge', 'p: the top divider is 90px at phone, over the 40px padding of its edge' ],
            self::dividerProblems( 'p', $planted, $space )
        );
        // A phone override that fits clears only the phone line; a height that is not px fails closed.
        $planted['module']['advanced']['dividers']['top']['phone'] = [ 'value' => [ 'height' => '32px' ] ];
        $this->assertCount( 2, self::dividerProblems( 'p', $planted, $space ) );
        $planted['module']['advanced']['dividers']['top']['desktop']['value']['height'] = '5vw';
        $this->assertStringContainsString( 'has no px height at desktop', implode( "\n", self::dividerProblems( 'p', $planted, $space ) ) );
    }

    public function testEveryMigratedRecipeIsServedValidUnderEveryLanguage(): void
    {
        $recipes = 0;
        foreach ( SectionRecipes::names() as $name ) {
            if ( ! str_contains( (string) SectionRecipes::rawMarkup( $name ), '"aied":{"rhythm":' ) ) {
                continue;
            }
            ++$recipes;
            foreach ( DesignLanguages::ids() as $langId ) {
                $label  = "$name under $langId";
                $markup = SectionRecipes::recipe( $name, $langId );
                $this->assertIsString( $markup, "$label is served" );
                $this->assertStringNotContainsString( '"aied"', $markup, "$label leaves a marker" );
                $this->assertStringNotContainsString( '{{aied:', $markup, "$label leaves a token" );
                $this->assertSame( [], KitResolver::leftovers( $markup ), $label );
                $this->assertSame( [], LanguageResolver::leftovers( $markup ), $label );
                $result = LayoutGate::check( '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->' );
                $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                $this->assertTrue( $result->isValid(), "$label must pass the layout gate, got: $codes" );
            }
        }
        $this->assertSame( 46, $recipes, 'the recipes with a rhythm marker' );
    }

    public function testNoMarkerTokenOrLeftoverReachesServedMarkup(): void
    {
        $served = 0;
        foreach ( SectionRecipes::names() as $name ) {
            foreach ( array_merge( [ null ], DesignLanguages::ids() ) as $langId ) {
                $label  = $name . ' under ' . ( $langId ?? 'the default look' );
                $markup = SectionRecipes::recipe( $name, $langId );
                $this->assertIsString( $markup, $label );
                $this->assertStringNotContainsString( '"aied"', $markup, $label );
                $this->assertStringNotContainsString( '{{aied:', $markup, $label );
                $this->assertSame( [], LanguageResolver::leftovers( $markup ), $label );
                $this->assertSame( [], KitResolver::leftovers( $markup ), $label );
                ++$served;
            }
        }
        $this->assertSame( 71 * 11, $served );
    }

    public function testRecipesFileIsSmallerThanBeforeTheRhythmMarkers(): void
    {
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE_RHYTHM, filesize( self::FILE ), 'migrating the section padding shrinks the recipe file (rhythm adapter plan, Task 5)' );
        $this->assertLessThan( 700 * 1024, filesize( self::FILE ), 'the 700 KB bound holds' );
    }

    public function testTheMigrationScriptIsIdempotent(): void
    {
        $out  = [];
        $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../scripts/migrate-rhythm-markers.php' ) . ' --check 2>&1', $out, $code );
        $this->assertSame( 0, $code, "migrate-rhythm-markers.php --check would change the file:\n" . implode( "\n", $out ) );
    }

    public function testRhythmMarkedRecipeProseAgreesWithItsRole(): void
    {
        $problems = [];
        $markers  = self::markersInFile();
        $checked  = 0;
        foreach ( self::library() as $recipe ) {
            $role = $markers[ $recipe['name'] . '|divi/section|0' ] ?? null;
            if ( null === $role ) {
                continue;
            }
            ++$checked;
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                $text = (string) ( $recipe[ $field ] ?? '' );
                if ( 1 === preg_match( self::PADDING_NUMBER, $text ) ) {
                    $problems[] = "{$recipe['name']}.$field names a padding number, which the language decides";
                }
                if ( 'open' !== $role && 1 === preg_match( self::OPEN_ONLY_PADDING, $text ) ) {
                    $problems[] = "{$recipe['name']}.$field promises tall or generous padding on a $role section";
                }
            }
        }
        $this->assertSame( 46, $checked );
        $this->assertSame( [], $problems );
        // The phrases catch the wording they were written for, and leave the library's own clauses alone.
        foreach ( [ '120px top and bottom padding', 'a padding of 80px', 'with 96 px padding' ] as $hit ) {
            $this->assertSame( 1, preg_match( self::PADDING_NUMBER, $hit ), $hit );
        }
        foreach ( [ 'one quote on the left (up to 760px wide), then a short rule; tall padding, no card', 'a 760px column. Tall padding' ] as $fine ) {
            $this->assertSame( 0, preg_match( self::PADDING_NUMBER, $fine ), $fine );
        }
        $this->assertSame( 1, preg_match( self::OPEN_ONLY_PADDING, 'tall padding, no card' ) );
        $this->assertSame( 1, preg_match( self::OPEN_ONLY_PADDING, 'generous padding around it' ) );
        $this->assertSame( 'open', $markers['quote-over-image|divi/section|0'], 'the one recipe that says "tall padding" is an open band' );
    }

    // ---- planted cases: each check fails on an unmigrated shape and passes on the clean one ----

    /** $markup with the old literals put back on every rhythm-marked section: desktop top and bottom, a tablet syncVertical. */
    private static function withOldPadding( string $markup, array $extra = [] ): string
    {
        return (string) preg_replace_callback(
            self::BLOCK,
            static function ( array $m ) use ( $extra ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( 'divi/section' !== $m[1] || ! is_array( $attrs ) || null === self::rhythmMarker( $attrs ) ) {
                    return $m[0];
                }
                $node = [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '112px', 'bottom' => '112px' ] ] ], 'tablet' => [ 'value' => [ 'padding' => [ 'syncVertical' => 'on' ] ] ] ] ] ] ];
                return str_replace( $m[2], RecipeTokens::encodeJson( array_replace_recursive( $attrs, $node, $extra ) ), $m[0] );
            },
            $markup
        );
    }

    public function testAnUnmigratedRecipeFailsEveryCheckAndTheCleanShapePasses(): void
    {
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        $kit = self::kitOf( $lang );
        foreach ( [ 'team-grid', 'proof-logo-wall', 'transition-wave-band' ] as $name ) {
            $raw     = ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $name ), self::IMAGE_BASE );
            $planted = self::withOldPadding( $raw );
            $count   = 0;
            $own     = implode( "\n", self::ownPaddingProblems( $name, $planted, $count ) );
            $this->assertSame( 1, $count, "$name is migrated" );
            foreach ( [ 'desktop.value.padding.top', 'desktop.value.padding.bottom', 'tablet.value.padding.syncVertical' ] as $leaf ) {
                $this->assertStringContainsString( "the migrated section must drop its own module.decoration.spacing.$leaf", $own, "$name $leaf" );
            }
            // Served, the language overwrites those literals (it wins at every breakpoint), but a stray side survives the merge.
            $this->assertSame( [], self::mergeTrapProblems( $name, $planted, (string) SectionRecipes::resolveMarkup( $planted, $lang, $kit ), $lang, $kit ) );
            $stray = self::withOldPadding( $raw, [ 'module' => [ 'decoration' => [ 'spacing' => [ 'phone' => [ 'value' => [ 'padding' => [ 'left' => '8px' ] ] ] ] ] ] ] );
            $trap  = implode( "\n", self::mergeTrapProblems( $name, $stray, (string) SectionRecipes::resolveMarkup( $stray, $lang, $kit ), $lang, $kit ) );
            $this->assertStringContainsString( 'keeps module.decoration.spacing.phone.value.padding.left under the spacing the language decides', $trap, $name );
            // The clean shape (the shipped one) passes both.
            $this->assertSame( [], self::ownPaddingProblems( $name, $raw ) );
            $this->assertSame( [], self::mergeTrapProblems( $name, $raw, (string) SectionRecipes::resolveMarkup( $raw, $lang, $kit ), $lang, $kit ) );
        }
        // A marker with an extra key is not a rhythm marker this test accepts.
        $this->expectException( \UnexpectedValueException::class );
        self::rhythmMarker( [ 'aied' => [ 'rhythm' => 'open', 'on' => 'dark' ] ] );
    }

    public function testTheMergeTrapCatchesStraysAndOverrides(): void
    {
        $lang = DesignLanguages::get( 'brutalist-flat' );
        $this->assertIsArray( $lang );
        $kit     = self::kitOf( $lang );
        $section = static fn ( array $extra = [] ): string => '<!-- wp:divi/section ' . json_encode( array_replace_recursive( [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '{{aied:color:bg}}' ] ] ] ] ], 'builderVersion' => '5.8.0' ], $extra, [ 'aied' => [ 'rhythm' => 'section' ] ] ), JSON_UNESCAPED_SLASHES ) . ' --><!-- /wp:divi/section -->';
        $run     = static fn ( string $raw, ?string $served = null ): string => implode( "\n", self::mergeTrapProblems( 'p', $raw, $served ?? (string) SectionRecipes::resolveMarkup( $raw, $lang, $kit ), $lang, $kit ) );

        $clean = $section();
        $this->assertSame( '', $run( $clean ), 'the clean shape passes' );
        $served = (string) SectionRecipes::resolveMarkup( $clean, $lang, $kit );
        $this->assertStringContainsString( '"tablet":{"value":{"padding":{"top":"48px","bottom":"48px","syncVertical":"on"}}}', $served, 'brutalist-flat writes its section rhythm 64/48/32' );

        // A stray hover padding survives the merge and is reported.
        $this->assertStringContainsString( 'keeps module.decoration.spacing.desktop.hover.padding.top under the spacing the language decides', $run( $section( [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'hover' => [ 'padding' => [ 'top' => '10px' ] ] ] ] ] ] ] ) ) );
        // An overridden tablet top, a changed background, a lost background, a gained key and a leftover marker in the served markup.
        $this->assertStringContainsString( 'module.decoration.spacing.tablet.value.padding.top is "10px", not the language\'s "48px"', $run( $clean, str_replace( '"top":"48px"', '"top":"10px"', $served ) ) );
        $bg = (string) $lang['colors']['bg'];
        $this->assertStringContainsString( 'module.decoration.background.desktop.value.color is "#123456", not the recipe\'s ' . json_encode( $bg ), $run( $clean, str_replace( '"color":"' . $bg . '"', '"color":"#123456"', $served ) ) );
        $this->assertStringContainsString( 'lacks module.decoration.background.desktop.value.color', $run( $clean, str_replace( '"background":{"desktop":{"value":{"color":"' . $bg . '"}}},', '', $served ) ) );
        $this->assertStringContainsString( 'gained module.decoration.zIndex', $run( $clean, str_replace( '"decoration":{', '"decoration":{"zIndex":{"desktop":{"value":"2"}},', $served ) ) );
        $this->assertStringContainsString( 'not resolved', $run( $clean, $clean ) );
        // Another language's served markup is not this language's.
        $other = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $other );
        $this->assertNotSame( '', $run( $clean, (string) SectionRecipes::resolveMarkup( $clean, $other, self::kitOf( $other ) ) ) );
    }
}
