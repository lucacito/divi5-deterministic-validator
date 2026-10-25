<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\TypeAudit;
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
require_once __DIR__ . '/Support/TypeAudit.php';

/**
 * Recipe-level guards for the headings and labels driven by a design language (marker `"aied":{"type":"display|title|eyebrow"}`
 * on a `divi/heading` or an eyebrow `divi/text`; type adapter, plan 2026-10-24, Task 5), kept apart from RecipeLibraryTest.
 * The language writes the weight, the letter spacing and (only when it asks for capitals) the capitalisation at the block's
 * desktop font path, so a migrated block must carry none of its own at ANY breakpoint or state (Ruling 4: a `none` role then
 * really shows Divi's default):
 *
 * - pinned: exactly the 206 blocks of MIGRATED carry a type marker, with that role; nothing is bespoke (cap 0) and every
 *   heading or uppercase label the audit sees without a marker is one of TypeAudit::EXEMPTIONS;
 * - own type: a marked block keeps no `weight`, `letterSpacing` or `capitalization` on its font path;
 * - merge trap: served under every language (with the language's own kit), a marked block holds exactly the language's patch
 *   at the font path and, for every other leaf, the raw block's value after the language's colour and font tokens (the kit
 *   step never touches a heading or text), and no marker;
 * - served: every recipe is valid through LayoutGate under every language and leaves no marker, token or leftover;
 * - prose: a recipe with a type marker does not describe the headline type the language decides.
 *
 * The recipe file is migrated ONLY by scripts/migrate-type-markers.php (`--check` proves it is idempotent: TypeMigrationTest
 * runs it). MIGRATED is a literal (generated once from TypeAudit at 77abc24, never read from the file at test time).
 */
class TypeMigrationTest extends TestCase
{
    /** Block => the font group whose breakpoints and states the language decides (LanguageAdapters::TYPE_PATHS). */
    private const FONT_PATHS = [
        'divi/heading' => 'title.decoration.font.font',
        'divi/text'    => 'content.decoration.bodyFont.body.font',
    ];

    /** The three keys the language decides, at any breakpoint and state of the font path. */
    private const TYPE_KEYS = '(weight|letterSpacing|capitalization)';

    /**
     * Headings and labels deliberately NOT driven by the language although they would classify: SHRINK-ONLY and EMPTY (cap 0).
     * (The four blocks the rule cannot place are TypeAudit::EXEMPTIONS, pinned below.)
     */
    private const BESPOKE_TYPE = [];

    private const BESPOKE_TYPE_MAX = 0;

    /** The file size before the type markers (77abc24): the migration must shrink the file. */
    private const SIZE_BEFORE_TYPE = 662636;

    /**
     * Recipes with a type marker whose prose may still match TYPE_DECIDED_PHRASES, with why. SHRINK-ONLY, cap 1.
     */
    private const TYPE_PROSE_EXEMPT = [
        'proof-press-quotes' => 'its "uppercase text mark" is the publication name, an exempt text in the display face',
    ];

    private const TYPE_PROSE_EXEMPT_MAX = 1;

    /** Phrases that describe the headline type a design language now decides, banned from a type-marked recipe's prose. */
    private const TYPE_DECIDED_PHRASES = [
        '/\buppercase\b/i',
        '/\bcapital(s| letters)\b/i',
        '/\ball caps\b/i',
        '/\bweight \d00\b/i',
        '/\bbold (line|headline|heading)\b/i',
    ];

    /**
     * The exempt blocks still carry their own literals (Task 4's EXEMPTIONS): key => [dotted font path leaf => value].
     */
    private const EXEMPT_LITERALS = [
        'shop-grid|divi/heading|0'       => [ 'title.decoration.font.font.desktop.value.family' => '{{aied:font:display}}' ],
        'proof-press-quotes|divi/text|0' => [ 'content.decoration.bodyFont.body.font.desktop.value.weight' => '700', 'content.decoration.bodyFont.body.font.desktop.value.capitalization' => 'uppercase', 'content.decoration.bodyFont.body.font.desktop.value.letterSpacing' => '3px' ],
        'proof-press-quotes|divi/text|3' => [ 'content.decoration.bodyFont.body.font.desktop.value.weight' => '700', 'content.decoration.bodyFont.body.font.desktop.value.capitalization' => 'uppercase', 'content.decoration.bodyFont.body.font.desktop.value.letterSpacing' => '3px' ],
        'proof-press-quotes|divi/text|6' => [ 'content.decoration.bodyFont.body.font.desktop.value.weight' => '700', 'content.decoration.bodyFont.body.font.desktop.value.capitalization' => 'uppercase', 'content.decoration.bodyFont.body.font.desktop.value.letterSpacing' => '3px' ],
    ];

    /**
     * The 206 type markers: "<recipe>|<block>|<index among that block name>" => role. Pinned as literals: a lost, added or
     * re-roled marker fails here. display 56 (h1 13 + h2 43), title 64 (h3), eyebrow 86 (53 h4 headings + 33 texts), in 56
     * recipes.
     */
    private const MIGRATED = [
        'hero-cta|divi/heading|0'                            => 'eyebrow',
        'hero-cta|divi/heading|1'                            => 'display',
        'section-intro|divi/heading|0'                       => 'display',
        'blurb-grid|divi/heading|0'                          => 'display',
        'icon-features|divi/heading|0'                       => 'display',
        'contact-form|divi/heading|0'                        => 'display',
        'testimonial|divi/heading|0'                         => 'display',
        'hero-pattern-centered|divi/heading|0'               => 'display',
        'hero-split-right|divi/heading|0'                    => 'eyebrow',
        'hero-split-right|divi/heading|1'                    => 'display',
        'hero-split-left-overlap|divi/heading|0'             => 'eyebrow',
        'hero-split-left-overlap|divi/heading|1'             => 'display',
        'hero-image-scrim|divi/heading|0'                    => 'eyebrow',
        'hero-image-scrim|divi/heading|1'                    => 'display',
        'hero-image-scrim|divi/text|1'                       => 'eyebrow',
        'hero-image-scrim|divi/text|2'                       => 'eyebrow',
        'hero-image-scrim|divi/text|3'                       => 'eyebrow',
        'hero-gradient-stats|divi/heading|0'                 => 'eyebrow',
        'hero-gradient-stats|divi/heading|1'                 => 'display',
        'hero-editorial-minimal|divi/heading|0'              => 'eyebrow',
        'hero-editorial-minimal|divi/heading|1'              => 'display',
        'hero-bordered-cells|divi/heading|0'                 => 'display',
        'hero-bordered-cells|divi/text|0'                    => 'eyebrow',
        'hero-bordered-cells|divi/text|2'                    => 'eyebrow',
        'hero-bordered-cells|divi/text|4'                    => 'eyebrow',
        'hero-spec-card|divi/heading|0'                      => 'display',
        'hero-spec-card|divi/text|2'                         => 'eyebrow',
        'hero-product-glow|divi/heading|0'                   => 'eyebrow',
        'hero-product-glow|divi/heading|1'                   => 'display',
        'header-title-band|divi/text|0'                      => 'eyebrow',
        'header-title-band|divi/heading|0'                   => 'display',
        'header-divider-intro|divi/heading|0'                => 'eyebrow',
        'header-divider-intro|divi/heading|1'                => 'display',
        'header-image-band|divi/text|0'                      => 'eyebrow',
        'header-image-band|divi/heading|0'                   => 'display',
        'features-bento|divi/heading|0'                      => 'eyebrow',
        'features-bento|divi/heading|1'                      => 'display',
        'features-bento|divi/heading|2'                      => 'title',
        'features-bento|divi/heading|3'                      => 'title',
        'features-bento|divi/heading|4'                      => 'title',
        'features-bento|divi/heading|5'                      => 'title',
        'features-bento|divi/heading|6'                      => 'title',
        'features-zigzag|divi/heading|0'                     => 'eyebrow',
        'features-zigzag|divi/heading|1'                     => 'display',
        'features-zigzag|divi/heading|2'                     => 'eyebrow',
        'features-zigzag|divi/heading|3'                     => 'title',
        'features-zigzag|divi/heading|4'                     => 'eyebrow',
        'features-zigzag|divi/heading|5'                     => 'title',
        'features-zigzag|divi/heading|6'                     => 'eyebrow',
        'features-zigzag|divi/heading|7'                     => 'title',
        'list-icon-dividers|divi/heading|0'                  => 'eyebrow',
        'list-icon-dividers|divi/heading|1'                  => 'display',
        'list-icon-dividers|divi/heading|2'                  => 'title',
        'list-icon-dividers|divi/heading|3'                  => 'title',
        'list-icon-dividers|divi/heading|4'                  => 'title',
        'list-icon-dividers|divi/heading|5'                  => 'title',
        'list-icon-dividers|divi/heading|6'                  => 'title',
        'checklist-two-column|divi/heading|0'                => 'eyebrow',
        'checklist-two-column|divi/heading|1'                => 'display',
        'steps-numbered|divi/heading|0'                      => 'eyebrow',
        'steps-numbered|divi/heading|1'                      => 'display',
        'steps-numbered|divi/heading|2'                      => 'title',
        'steps-numbered|divi/heading|3'                      => 'title',
        'steps-numbered|divi/heading|4'                      => 'title',
        'steps-horizontal|divi/heading|0'                    => 'eyebrow',
        'steps-horizontal|divi/heading|1'                    => 'display',
        'steps-horizontal|divi/heading|2'                    => 'title',
        'steps-horizontal|divi/heading|3'                    => 'title',
        'steps-horizontal|divi/heading|4'                    => 'title',
        'steps-horizontal|divi/heading|5'                    => 'title',
        'timeline-vertical|divi/heading|0'                   => 'eyebrow',
        'timeline-vertical|divi/heading|1'                   => 'display',
        'timeline-vertical|divi/heading|2'                   => 'eyebrow',
        'timeline-vertical|divi/heading|3'                   => 'title',
        'timeline-vertical|divi/heading|4'                   => 'eyebrow',
        'timeline-vertical|divi/heading|5'                   => 'title',
        'timeline-vertical|divi/heading|6'                   => 'eyebrow',
        'timeline-vertical|divi/heading|7'                   => 'title',
        'timeline-vertical|divi/heading|8'                   => 'eyebrow',
        'timeline-vertical|divi/heading|9'                   => 'title',
        'features-accordion-image|divi/heading|0'            => 'eyebrow',
        'features-accordion-image|divi/heading|1'            => 'display',
        'compare-two-panels|divi/heading|0'                  => 'eyebrow',
        'compare-two-panels|divi/heading|1'                  => 'display',
        'compare-two-panels|divi/heading|2'                  => 'eyebrow',
        'compare-two-panels|divi/heading|3'                  => 'title',
        'compare-two-panels|divi/heading|4'                  => 'eyebrow',
        'compare-two-panels|divi/heading|5'                  => 'title',
        'features-carousel-strip|divi/heading|0'             => 'eyebrow',
        'features-carousel-strip|divi/heading|1'             => 'display',
        'features-carousel-strip|divi/heading|2'             => 'title',
        'features-carousel-strip|divi/heading|3'             => 'title',
        'features-carousel-strip|divi/heading|4'             => 'title',
        'features-carousel-strip|divi/heading|5'             => 'title',
        'features-carousel-strip|divi/heading|6'             => 'title',
        'features-carousel-strip|divi/heading|7'             => 'title',
        'stats-big-numbers|divi/heading|0'                   => 'eyebrow',
        'stats-big-numbers|divi/heading|1'                   => 'display',
        'proof-press-quotes|divi/heading|0'                  => 'eyebrow',
        'proof-press-quotes|divi/heading|1'                  => 'display',
        'stats-counter-band|divi/heading|0'                  => 'eyebrow',
        'stats-counter-band|divi/heading|1'                  => 'display',
        'stats-circle-counters|divi/heading|0'               => 'eyebrow',
        'stats-circle-counters|divi/heading|1'               => 'display',
        'stats-big-captions|divi/heading|0'                  => 'eyebrow',
        'stats-big-captions|divi/heading|1'                  => 'display',
        'stats-big-captions|divi/text|2'                     => 'eyebrow',
        'stats-big-captions|divi/text|4'                     => 'eyebrow',
        'stats-big-captions|divi/text|6'                     => 'eyebrow',
        'stats-big-captions|divi/text|8'                     => 'eyebrow',
        'testimonials-slider-quote|divi/heading|0'           => 'eyebrow',
        'testimonials-slider-quote|divi/heading|1'           => 'display',
        'testimonials-avatar-grid|divi/heading|0'            => 'eyebrow',
        'testimonials-avatar-grid|divi/heading|1'            => 'display',
        'pricing-three-tier|divi/heading|0'                  => 'eyebrow',
        'pricing-three-tier|divi/heading|1'                  => 'display',
        'pricing-three-tier|divi/heading|2'                  => 'title',
        'pricing-three-tier|divi/text|7'                     => 'eyebrow',
        'pricing-three-tier|divi/heading|3'                  => 'title',
        'pricing-three-tier|divi/heading|4'                  => 'title',
        'pricing-two-plan-highlight|divi/heading|0'          => 'eyebrow',
        'pricing-two-plan-highlight|divi/heading|1'          => 'display',
        'pricing-two-plan-highlight|divi/text|1'             => 'eyebrow',
        'pricing-two-plan-highlight|divi/heading|2'          => 'title',
        'pricing-two-plan-highlight|divi/text|9'             => 'eyebrow',
        'pricing-two-plan-highlight|divi/heading|3'          => 'title',
        'pricing-comparison-table|divi/heading|0'            => 'eyebrow',
        'pricing-comparison-table|divi/heading|1'            => 'display',
        'team-grid|divi/heading|0'                           => 'eyebrow',
        'team-grid|divi/heading|1'                           => 'display',
        'team-grid|divi/heading|2'                           => 'title',
        'team-grid|divi/text|1'                              => 'eyebrow',
        'team-grid|divi/heading|3'                           => 'title',
        'team-grid|divi/text|2'                              => 'eyebrow',
        'team-grid|divi/heading|4'                           => 'title',
        'team-grid|divi/text|3'                              => 'eyebrow',
        'team-grid|divi/heading|5'                           => 'title',
        'team-grid|divi/text|4'                              => 'eyebrow',
        'founder-note|divi/heading|0'                        => 'eyebrow',
        'founder-note|divi/heading|1'                        => 'display',
        'values-list|divi/heading|0'                         => 'eyebrow',
        'values-list|divi/heading|1'                         => 'display',
        'values-list|divi/heading|2'                         => 'title',
        'values-list|divi/heading|3'                         => 'title',
        'values-list|divi/heading|4'                         => 'title',
        'values-list|divi/heading|5'                         => 'title',
        'faq-accordion|divi/heading|0'                       => 'eyebrow',
        'faq-accordion|divi/heading|1'                       => 'display',
        'faq-two-column|divi/heading|0'                      => 'eyebrow',
        'faq-two-column|divi/heading|1'                      => 'display',
        'faq-two-column|divi/heading|2'                      => 'title',
        'faq-two-column|divi/heading|3'                      => 'title',
        'faq-two-column|divi/heading|4'                      => 'title',
        'faq-two-column|divi/heading|5'                      => 'title',
        'faq-two-column|divi/heading|6'                      => 'title',
        'faq-two-column|divi/heading|7'                      => 'title',
        'faq-toggle-list|divi/heading|0'                     => 'eyebrow',
        'faq-toggle-list|divi/heading|1'                     => 'display',
        'story-timeline|divi/heading|0'                      => 'eyebrow',
        'story-timeline|divi/heading|1'                      => 'display',
        'story-timeline|divi/heading|2'                      => 'title',
        'story-timeline|divi/heading|3'                      => 'title',
        'story-timeline|divi/heading|4'                      => 'title',
        'story-timeline|divi/heading|5'                      => 'title',
        'cta-band|divi/heading|0'                            => 'display',
        'cta-split-form|divi/heading|0'                      => 'eyebrow',
        'cta-split-form|divi/heading|1'                      => 'display',
        'cta-split-form|divi/heading|2'                      => 'title',
        'cta-card-over-image|divi/heading|0'                 => 'eyebrow',
        'cta-card-over-image|divi/heading|1'                 => 'display',
        'cta-countdown|divi/heading|0'                       => 'eyebrow',
        'cta-countdown|divi/heading|1'                       => 'display',
        'gallery-masonry-style|divi/heading|0'               => 'eyebrow',
        'gallery-masonry-style|divi/heading|1'               => 'display',
        'portfolio-featured-grid|divi/heading|0'             => 'eyebrow',
        'portfolio-featured-grid|divi/heading|1'             => 'display',
        'portfolio-featured-grid|divi/heading|2'             => 'title',
        'portfolio-featured-grid|divi/text|0'                => 'eyebrow',
        'portfolio-featured-grid|divi/heading|3'             => 'title',
        'portfolio-featured-grid|divi/text|1'                => 'eyebrow',
        'portfolio-featured-grid|divi/heading|4'             => 'title',
        'portfolio-featured-grid|divi/text|2'                => 'eyebrow',
        'portfolio-featured-grid|divi/heading|5'             => 'title',
        'portfolio-featured-grid|divi/text|3'                => 'eyebrow',
        'portfolio-featured-grid|divi/heading|6'             => 'title',
        'portfolio-featured-grid|divi/text|4'                => 'eyebrow',
        'before-after-compare|divi/heading|0'                => 'eyebrow',
        'before-after-compare|divi/heading|1'                => 'display',
        'before-after-compare|divi/text|1'                   => 'eyebrow',
        'before-after-compare|divi/text|3'                   => 'eyebrow',
        'contact-location-form|divi/text|0'                  => 'eyebrow',
        'contact-location-form|divi/text|2'                  => 'eyebrow',
        'contact-location-form|divi/heading|0'               => 'eyebrow',
        'contact-location-form|divi/heading|1'               => 'display',
        'contact-details-form|divi/heading|0'                => 'eyebrow',
        'contact-details-form|divi/heading|1'                => 'display',
        'contact-details-form|divi/text|0'                   => 'eyebrow',
        'contact-details-form|divi/text|2'                   => 'eyebrow',
        'contact-details-form|divi/text|4'                   => 'eyebrow',
        'contact-details-form|divi/text|6'                   => 'eyebrow',
        'transition-wave-band|divi/heading|0'                => 'display',
        'transition-overlap-panel|divi/heading|0'            => 'eyebrow',
        'transition-overlap-panel|divi/heading|1'            => 'display',
        'transition-overlap-panel|divi/heading|2'            => 'title',
        'transition-overlap-panel|divi/heading|3'            => 'title',
        'transition-overlap-panel|divi/heading|4'            => 'title',
    ];

    private const BLOCK = '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s';

    private const FILE = __DIR__ . '/../wp-plugin/data/section-recipes.json';

    /**
     * The role of a type marker in $attrs, or null (no marker, or a kit marker). A type marker with any key besides `type`
     * throws.
     *
     * @param array<string,mixed> $attrs
     */
    public static function typeMarker( array $attrs ): ?string
    {
        $m = $attrs['aied'] ?? null;
        if ( ! is_array( $m ) || ! array_key_exists( 'type', $m ) ) {
            return null;
        }
        if ( 1 !== count( $m ) || ! is_string( $m['type'] ) ) {
            throw new \UnexpectedValueException( 'a type marker is exactly {"type":"<role>"}: ' . json_encode( $m ) );
        }
        return $m['type'];
    }

    /** Opening block comments of $markup in document order: list of [name, attrs, index among that name]. @return list<array{0:string,1:array<string,mixed>,2:int}> */
    public static function blocks( string $markup ): array
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

    /** True when $path is a weight, letter spacing or capitalisation leaf at any breakpoint and state of $block's font path. */
    private static function isTypeLeaf( string $block, string $path ): bool
    {
        $font = self::FONT_PATHS[ $block ] ?? null;
        return null !== $font && 1 === preg_match( '/^' . preg_quote( $font, '/' ) . '\.[^.]+\.[^.]+\.' . self::TYPE_KEYS . '$/', $path );
    }

    /** The recipes of the file. @return list<array<string,mixed>> */
    private static function library(): array
    {
        return (array) json_decode( (string) file_get_contents( self::FILE ), true );
    }

    /** "<recipe>|<block>|<index>" => role of every type marker of the recipe file (parsed from the block comments). @return array<string,string> */
    private static function markersInFile(): array
    {
        $out = [];
        foreach ( self::library() as $recipe ) {
            foreach ( self::blocks( (string) $recipe['markup'] ) as [ $name, $attrs, $i ] ) {
                $role = self::typeMarker( $attrs );
                if ( null !== $role ) {
                    $out[ $recipe['name'] . "|$name|$i" ] = $role;
                }
            }
        }
        return $out;
    }

    /**
     * What the RAW markup's marked blocks still carry of their own ([] = fine): a weight, letter spacing or capitalisation at
     * any breakpoint or state of the font path.
     *
     * @return list<string>
     */
    public static function ownTypeProblems( string $label, string $markup, int &$checked = 0 ): array
    {
        $out = [];
        foreach ( self::blocks( $markup ) as [ $name, $attrs, $i ] ) {
            $role = self::typeMarker( $attrs );
            if ( null === $role ) {
                continue;
            }
            ++$checked;
            foreach ( array_keys( self::leaves( $attrs ) ) as $path ) {
                if ( self::isTypeLeaf( $name, $path ) ) {
                    $out[] = "$label $name#$i ($role): the migrated block must drop its own $path";
                }
            }
        }
        return $out;
    }

    /**
     * The merge trap: for every block that carries a type marker in $raw, the same block (name and index) in $resolved (served
     * under $lang): the patch's leaves hold exactly the patch's values, no other weight/letterSpacing/capitalisation leaf is on
     * the font path, every other leaf equals the raw block's after RecipeTokens::resolve under $lang (nothing lost, added or
     * changed), and no `aied` key is left.
     *
     * @param array<string,mixed> $lang
     * @return list<string>
     */
    public static function mergeTrapProblems( string $label, string $raw, string $resolved, array $lang, int &$checked = 0 ): array
    {
        $out   = [];
        $after = [];
        foreach ( self::blocks( $resolved ) as [ $name, $attrs, $i ] ) {
            $after[ "$name#$i" ] = $attrs;
        }
        foreach ( self::blocks( $raw ) as [ $name, $attrs, $i ] ) {
            $role = self::typeMarker( $attrs );
            if ( null === $role ) {
                continue;
            }
            ++$checked;
            $where = "$label $name#$i ($role)";
            $got   = $after[ "$name#$i" ] ?? null;
            $patch = LanguageAdapters::patch( $name, 'type', $role, $lang );
            if ( ! is_array( $got ) || ! is_array( $patch ) || isset( $got['aied'] ) ) {
                $out[] = "$where: not resolved";
                continue;
            }
            $bare = $attrs;
            unset( $bare['aied'] );
            $passed = self::blocks( RecipeTokens::resolve( '<!-- wp:' . $name . ' ' . json_encode( $bare, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->', $lang ) )[0][1] ?? [];
            $want   = self::leaves( $patch );
            $keep   = self::leaves( $passed );
            $have   = self::leaves( $got );
            foreach ( $have as $path => $value ) {
                if ( array_key_exists( $path, $want ) ) {
                    if ( $value !== $want[ $path ] ) {
                        $out[] = "$where: $path is " . json_encode( $value ) . ', not the language\'s ' . json_encode( $want[ $path ] );
                    }
                } elseif ( self::isTypeLeaf( $name, $path ) ) {
                    $out[] = "$where: keeps $path that the language does not write";
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

    public function testTheMarkersArePinnedAndNothingIsBespoke(): void
    {
        $pinned = self::MIGRATED;
        $found  = self::markersInFile();
        ksort( $pinned );
        ksort( $found );
        $this->assertSame( $pinned, $found, 'exactly these blocks carry a type marker, with this role' );
        $this->assertCount( 206, $found );
        $spelled = array_sum( array_map( static fn ( array $r ): int => substr_count( (string) $r['markup'], '"aied":{"type":' ), self::library() ) );
        $this->assertSame( 206, $spelled, 'every marker is spelled the one way, as a top-level key' );
        $roles = array_count_values( array_map( static fn ( string $k, string $r ): string => explode( '|', $k )[1] . ' ' . $r, array_keys( $found ), $found ) );
        ksort( $roles );
        $this->assertSame( [ 'divi/heading display' => 56, 'divi/heading eyebrow' => 53, 'divi/heading title' => 64, 'divi/text eyebrow' => 33 ], $roles );
        $this->assertCount( 56, array_unique( array_map( static fn ( string $k ): string => explode( '|', $k )[0], array_keys( $found ) ) ), 'the 206 markers sit in 56 recipes' );
        // Every heading and uppercase label the audit sees is marked, with the role the rule gives it, or a named exemption.
        foreach ( TypeAudit::blocks( self::library() ) as $r ) {
            $key = TypeAudit::key( $r );
            if ( $r['marked'] ) {
                $this->assertSame( self::MIGRATED[ $key ] ?? null, $r['role'], $key );
                continue;
            }
            $this->assertArrayHasKey( $key, TypeAudit::EXEMPTIONS, "$key is a heading or an uppercase label with no type marker: give it one (BESPOKE_TYPE never grows)" );
            $this->assertArrayNotHasKey( $key, self::BESPOKE_TYPE );
        }
        $this->assertLessThanOrEqual( self::BESPOKE_TYPE_MAX, count( self::BESPOKE_TYPE ), 'shrink-only: BESPOKE_TYPE can never grow' );
        $this->assertSame( 0, self::BESPOKE_TYPE_MAX, 'cap 0: every heading and label is language-driven or a named exemption' );
        $this->assertSame( [], self::BESPOKE_TYPE );
    }

    public function testMarkedBlocksCarryNoTypeOfTheirOwn(): void
    {
        $problems = [];
        $checked  = 0;
        foreach ( self::library() as $recipe ) {
            $problems = array_merge( $problems, self::ownTypeProblems( (string) $recipe['name'], (string) $recipe['markup'], $checked ) );
        }
        $this->assertSame( 206, $checked, 'every type marker is checked for its own weight, tracking and capitals' );
        $this->assertSame( [], $problems, 'a marked block keeps its own weight, letter spacing or capitalisation (Ruling 4)' );
    }

    public function testMarkedBlocksKeepEverythingElseUnderEveryLanguage(): void
    {
        $problems = [];
        $blocks   = 0;
        foreach ( self::library() as $recipe ) {
            $name = (string) $recipe['name'];
            $raw  = (string) SectionRecipes::rawMarkup( $name );
            if ( ! str_contains( $raw, '"aied":{"type":' ) ) {
                continue;
            }
            foreach ( DesignLanguages::ids() as $n => $langId ) {
                $lang = DesignLanguages::get( $langId );
                $this->assertIsArray( $lang );
                $seen     = 0;
                $problems = array_merge( $problems, self::mergeTrapProblems( "$name/$langId", $raw, (string) SectionRecipes::recipe( $name, $langId ), $lang, $seen ) );
                if ( 0 === $n ) {
                    $blocks += $seen;
                }
            }
        }
        $this->assertSame( 206, $blocks, 'the merge trap walks all 206 type markers' );
        $this->assertSame( [], array_slice( array_values( array_unique( $problems ) ), 0, 40 ), 'a marked heading or label keeps everything but the type the language writes' );
    }

    public function testTheExemptionsAreExactlyTheNamedBlocks(): void
    {
        $this->assertSame( array_keys( TypeAudit::EXEMPTIONS ), array_keys( self::EXEMPT_LITERALS ), 'the audit names exactly these 4 blocks, in this order' );
        $byKey = [];
        foreach ( self::library() as $recipe ) {
            foreach ( self::blocks( (string) $recipe['markup'] ) as [ $name, $attrs, $i ] ) {
                $byKey[ $recipe['name'] . "|$name|$i" ] = $attrs;
            }
        }
        foreach ( self::EXEMPT_LITERALS as $key => $literals ) {
            $this->assertNotSame( '', trim( TypeAudit::EXEMPTIONS[ $key ] ), "$key has a reason" );
            $this->assertArrayHasKey( $key, $byKey, "$key still exists" );
            $this->assertArrayNotHasKey( 'aied', $byKey[ $key ], "$key stays literal: no marker of any kind" );
            $leaves = self::leaves( $byKey[ $key ] );
            foreach ( $literals as $path => $value ) {
                $this->assertSame( $value, $leaves[ $path ] ?? null, "$key still carries its own $path" );
            }
        }
        $this->assertArrayNotHasKey( 'title.decoration.font.font.desktop.value.headingLevel', self::leaves( $byKey['shop-grid|divi/heading|0'] ), 'shop-grid\'s title still has no level' );
    }

    public function testEveryMigratedRecipeIsServedValidUnderEveryLanguage(): void
    {
        $recipes = 0;
        foreach ( SectionRecipes::names() as $name ) {
            if ( ! str_contains( (string) SectionRecipes::rawMarkup( $name ), '"aied":{"type":' ) ) {
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
        $this->assertSame( 56, $recipes, 'the recipes with a type marker' );
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

    public function testRecipesFileIsSmallerThanBeforeTheTypeMarkers(): void
    {
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE_TYPE, filesize( self::FILE ), 'migrating the headings and labels shrinks the recipe file (type adapter plan, Task 5)' );
        $this->assertLessThan( 700 * 1024, filesize( self::FILE ), 'the 700 KB bound holds' );
    }

    public function testTheMigrationScriptIsIdempotent(): void
    {
        $out  = [];
        $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../scripts/migrate-type-markers.php' ) . ' --check 2>&1', $out, $code );
        $this->assertSame( 0, $code, "migrate-type-markers.php --check would change the file:\n" . implode( "\n", $out ) );
    }

    public function testTypeMarkedRecipeProseDoesNotDescribeTheHeadlineType(): void
    {
        $problems = [];
        $exempted = [];
        foreach ( self::library() as $recipe ) {
            if ( ! str_contains( (string) $recipe['markup'], '"aied":{"type":' ) ) {
                continue;
            }
            foreach ( [ 'title', 'description', 'when' ] as $field ) {
                foreach ( self::TYPE_DECIDED_PHRASES as $phrase ) {
                    if ( 1 === preg_match( $phrase, (string) ( $recipe[ $field ] ?? '' ) ) ) {
                        if ( isset( self::TYPE_PROSE_EXEMPT[ $recipe['name'] ] ) ) {
                            $exempted[ $recipe['name'] ] = true;
                            continue;
                        }
                        $problems[] = $recipe['name'] . ".$field matches $phrase";
                    }
                }
            }
        }
        $this->assertSame( [], $problems, 'the design language decides the headline type: say a headline or a label, not its weight or capitals' );
        $this->assertSame( array_keys( self::TYPE_PROSE_EXEMPT ), array_keys( $exempted ), 'every prose exemption is still needed (shrink-only)' );
        $this->assertLessThanOrEqual( self::TYPE_PROSE_EXEMPT_MAX, count( self::TYPE_PROSE_EXEMPT ) );
        $this->assertSame( 1, self::TYPE_PROSE_EXEMPT_MAX, 'cap 1' );
        // The phrases catch the wording they were written for, and leave the reworded prose alone.
        foreach ( [ 'a small uppercase kicker', 'the role in small capitals', 'set in ALL CAPS', 'a headline at weight 700', 'one bold line and a button', 'a bold headline' ] as $old ) {
            $hit = false;
            foreach ( self::TYPE_DECIDED_PHRASES as $phrase ) {
                $hit = $hit || 1 === preg_match( $phrase, $old );
            }
            $this->assertTrue( $hit, $old );
        }
        foreach ( [ 'a small kicker', 'the role as a small label', 'one strong line and a button', 'a very large display headline', 'a light-weight placeholder numeral' ] as $fine ) {
            foreach ( self::TYPE_DECIDED_PHRASES as $phrase ) {
                $this->assertSame( 0, preg_match( $phrase, $fine ), $fine );
            }
        }
    }

    // ---- planted cases: each check fails on an unmigrated shape and passes on the clean one ----

    /** $markup with the old literals put back on every type-marked block: desktop weight and uppercase, a tablet letter spacing. */
    private static function withOldType( string $markup ): string
    {
        return (string) preg_replace_callback(
            self::BLOCK,
            static function ( array $m ): string {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : null;
                if ( ! is_array( $attrs ) || null === self::typeMarker( $attrs ) ) {
                    return $m[0];
                }
                $node = [ 'desktop' => [ 'value' => [ 'weight' => '800', 'capitalization' => 'uppercase' ] ], 'tablet' => [ 'value' => [ 'letterSpacing' => '9px' ] ] ];
                foreach ( array_reverse( explode( '.', self::FONT_PATHS[ $m[1] ] ) ) as $k ) {
                    $node = [ $k => $node ];
                }
                return str_replace( $m[2], RecipeTokens::encodeJson( array_replace_recursive( $attrs, $node ) ), $m[0] );
            },
            $markup
        );
    }

    public function testAnUnmigratedRecipeFailsEveryCheckAndTheCleanShapePasses(): void
    {
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        $kit = (array) StyleKits::get( (string) $lang['kit'] );
        foreach ( [ 'team-grid', 'hero-cta', 'features-zigzag' ] as $name ) {
            $raw     = (string) SectionRecipes::rawMarkup( $name );
            $planted = self::withOldType( $raw );
            $count   = 0;
            $own     = implode( "\n", self::ownTypeProblems( $name, $planted, $count ) );
            $this->assertGreaterThan( 0, $count, "$name is migrated" );
            foreach ( [ 'desktop.value.weight', 'desktop.value.capitalization', 'tablet.value.letterSpacing' ] as $leaf ) {
                $this->assertStringContainsString( "the migrated block must drop its own title.decoration.font.font.$leaf", $own, "$name $leaf" );
            }
            // Served, the desktop literals are overwritten by the language, but the tablet tracking survives the deep merge.
            $trap = implode( "\n", self::mergeTrapProblems( $name, $planted, (string) SectionRecipes::resolveMarkup( $planted, $lang, $kit ), $lang ) );
            $this->assertStringContainsString( 'keeps title.decoration.font.font.tablet.value.letterSpacing that the language does not write', $trap, $name );
            // The clean shape (the shipped one) passes both.
            $this->assertSame( [], self::ownTypeProblems( $name, $raw ) );
            $this->assertSame( [], self::mergeTrapProblems( $name, $raw, (string) SectionRecipes::resolveMarkup( $raw, $lang, $kit ), $lang ) );
        }
        // An eyebrow text is checked at its body font path.
        $text = '<!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"family":"{{aied:font:eyebrow}}","size":"12px"}}}}}}},"aied":{"type":"eyebrow"}} /-->';
        $this->assertSame( [], self::ownTypeProblems( 'p', $text ) );
        $this->assertSame( [ 'p divi/text#0 (eyebrow): the migrated block must drop its own content.decoration.bodyFont.body.font.desktop.value.weight', 'p divi/text#0 (eyebrow): the migrated block must drop its own content.decoration.bodyFont.body.font.desktop.value.capitalization', 'p divi/text#0 (eyebrow): the migrated block must drop its own content.decoration.bodyFont.body.font.tablet.value.letterSpacing' ], self::ownTypeProblems( 'p', self::withOldType( $text ) ) );
        // A marker with an extra key is not a type marker this test accepts.
        $this->expectException( \UnexpectedValueException::class );
        self::typeMarker( [ 'aied' => [ 'type' => 'display', 'on' => 'light' ] ] );
    }

    public function testTheMergeTrapCatchesStraysAndOverrides(): void
    {
        $lang = DesignLanguages::get( 'brutalist-flat' );
        $this->assertIsArray( $lang );
        $kit  = (array) StyleKits::get( (string) $lang['kit'] );
        $head = static fn ( array $value, array $extra = [] ): string => '<!-- wp:divi/heading ' . json_encode( array_replace_recursive( [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => $value ] ] ] ] ], 'builderVersion' => '5.8.0' ], $extra, [ 'aied' => [ 'type' => 'display' ] ] ), JSON_UNESCAPED_SLASHES ) . ' /-->';
        $base = [ 'headingLevel' => 'h1', 'family' => '{{aied:font:display}}', 'size' => '64px', 'color' => '{{aied:color:text}}' ];
        $run  = static fn ( string $raw, ?string $served = null ): string => implode( "\n", self::mergeTrapProblems( 'p', $raw, $served ?? (string) SectionRecipes::resolveMarkup( $raw, $lang, $kit ), $lang ) );

        $clean = $head( $base );
        $this->assertSame( '', $run( $clean ), 'the clean shape passes' );
        $served = (string) SectionRecipes::resolveMarkup( $clean, $lang, $kit );
        $this->assertStringContainsString( '"capitalization":"uppercase"', $served, 'brutalist-flat writes capitals on display' );

        // A stray tablet weight survives the merge and is reported.
        $this->assertStringContainsString( 'keeps title.decoration.font.font.tablet.value.weight that the language does not write', $run( $head( $base, [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'tablet' => [ 'value' => [ 'weight' => '300' ] ] ] ] ] ] ] ) ) );
        // A hover-state capitalisation as well.
        $this->assertStringContainsString( 'keeps title.decoration.font.font.desktop.hover.capitalization', $run( $head( $base, [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'hover' => [ 'capitalization' => 'lowercase' ] ] ] ] ] ] ] ) ) );
        // A changed size, a lost colour, a gained key, an overridden weight and a leftover marker in the served markup.
        $this->assertStringContainsString( 'title.decoration.font.font.desktop.value.size is "10px", not the recipe\'s "64px"', $run( $clean, str_replace( '"size":"64px"', '"size":"10px"', $served ) ) );
        $this->assertStringContainsString( 'lacks title.decoration.font.font.desktop.value.color', $run( $clean, (string) preg_replace( '/"color":"[^"]*",?/', '', $served ) ) );
        $this->assertStringContainsString( 'gained module.decoration.zIndex', $run( $clean, str_replace( '"builderVersion"', '"module":{"decoration":{"zIndex":{"desktop":{"value":"2"}}}},"builderVersion"', $served ) ) );
        $weight = (string) $lang['type']['display']['weight'];
        $this->assertStringContainsString( 'title.decoration.font.font.desktop.value.weight is "100", not the language\'s "' . $weight . '"', $run( $clean, str_replace( '"weight":"' . $weight . '"', '"weight":"100"', $served ) ) );
        $this->assertStringContainsString( 'not resolved', $run( $clean, $clean ) );
        // Another language's served markup is not this language's.
        $other = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $other );
        $this->assertNotSame( '', $run( $clean, (string) SectionRecipes::resolveMarkup( $clean, $other, (array) StyleKits::get( (string) $other['kit'] ) ) ) );
    }
}
