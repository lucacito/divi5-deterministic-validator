<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LandingGuide;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\SiteGuide;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/LandingGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SiteGuide.php';

/**
 * The guides point the AI at recipes. Every pointer must name a recipe (or a recipe role) that exists, and a recipe offered for
 * a persuasion stage or a page type must carry a role that fits it. A wrong pointer (a renamed recipe, a card grid offered for
 * the FAQ) fails here.
 */
class GuideRecipePointersTest extends TestCase
{
    /**
     * The landing guide's conversion flow: a key found in a stage's bold title => the recipe roles that may be offered there.
     * Every stage must match exactly one key.
     */
    private const STAGE_ROLES = [
        'Hero'             => [ 'hero', 'header' ],
        'Problem'          => [ 'features', 'content' ],
        'Solution'         => [ 'features', 'content' ],
        'Benefits'         => [ 'features', 'content' ],
        'Social proof'     => [ 'proof', 'stats', 'testimonials', 'gallery', 'team' ],
        'How it works'     => [ 'process' ],
        'Feature'          => [ 'features' ],
        'Pricing'          => [ 'pricing' ],
        'FAQ'              => [ 'faq' ],
        'Final conversion' => [ 'cta', 'contact', 'hero' ],
        'Transitions'      => [ 'transition' ],
    ];

    /** The site guide's page types: a key found in a bullet's bold title => the recipe roles that may be offered for that page. */
    private const PAGE_ROLES = [
        'Home'      => [ 'hero', 'proof', 'features', 'process', 'stats', 'testimonials', 'cta', 'transition', 'gallery', 'content' ],
        'About'     => [ 'header', 'team', 'content', 'stats', 'testimonials', 'cta' ],
        'Services'  => [ 'header', 'features', 'process', 'pricing', 'faq', 'testimonials', 'cta' ],
        'Pricing'   => [ 'header', 'pricing', 'faq', 'proof', 'cta' ],
        'Portfolio' => [ 'header', 'gallery', 'testimonials', 'cta' ],
        'FAQ'       => [ 'header', 'faq', 'contact', 'cta' ],
        'Contact'   => [ 'header', 'contact' ],
        'Blog'      => [ 'header', 'blog' ],
    ];

    /** How many bold-titled segments of each guide section carry pointers (counted 2026-10-18): a dropped segment fails. */
    private const LANDING_SEGMENTS = 11;
    private const SITE_SEGMENTS    = 11;

    /** Backticked kebab-case words in the guides that are not recipe names (topics, ids, settings). */
    private const NOT_RECIPES = [ 'gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color', 'gcid-link-color', 'overflow-y' ];

    /**
     * Recipe mentions in a text: (1) every backticked kebab-case word that is not a topic, a design language id or a listed
     * non-recipe (so a backticked typo is reported as "not a recipe"); (2) a backticked single word that is a recipe name
     * (`slider`, `testimonial`); (3) every kebab-case recipe name written WITHOUT backticks, at a word boundary (not part of a
     * longer word, a path such as `divi/contact-form` or a file name). A single-word name without backticks is plain prose
     * ("a slider") and is not counted.
     *
     * @return list<string>
     */
    private static function recipeMentions( string $text ): array
    {
        $names = SectionRecipes::names();
        $skip  = array_merge( StyleGuide::topics(), DesignLanguages::ids(), self::NOT_RECIPES );
        $out   = [];
        preg_match_all( '/`([a-z][a-z0-9]*(?:-[a-z0-9]+)+)`/', $text, $m );
        foreach ( $m[1] as $word ) {
            if ( ! in_array( $word, $skip, true ) ) {
                $out[ $word ] = true;
            }
        }
        preg_match_all( '/`([a-z][a-z0-9]*)`/', $text, $m );
        foreach ( $m[1] as $word ) {
            if ( in_array( $word, $names, true ) ) {
                $out[ $word ] = true;
            }
        }
        foreach ( $names as $name ) {
            if ( str_contains( $name, '-' ) && 1 === preg_match( '/(?<![A-Za-z0-9_\/.-])' . preg_quote( $name, '/' ) . '(?![A-Za-z0-9_\/-]|\.[A-Za-z0-9])/', $text ) ) {
                $out[ $name ] = true;
            }
        }

        return array_keys( $out );
    }

    /** @return list<string> every `role=<role>` pointer */
    private static function roleMentions( string $text ): array
    {
        preg_match_all( '/`role=([a-z]+)`/', $text, $m );

        return array_values( array_unique( $m[1] ) );
    }

    /**
     * Problems with the pointers of one text: unknown recipe names and roles anywhere, and, inside the given section, an offered
     * recipe or role that does not fit the segment it is offered for.
     *
     * @param array<string,list<string>> $map segment key => allowed roles
     * @param int $expectedSegments how many segments of the section must match a key (0 = at least one), so a segment dropped
     *                              or merged by a formatting change fails instead of passing unchecked
     * @return list<string>
     */
    public static function problems( string $label, string $text, string $sectionHeading, array $map, int $expectedSegments = 0 ): array
    {
        $names    = SectionRecipes::names();
        $problems = [];
        foreach ( self::recipeMentions( $text ) as $name ) {
            if ( ! in_array( $name, $names, true ) ) {
                $problems[] = "$label: `$name` is not a recipe";
            }
        }
        foreach ( self::roleMentions( $text ) as $role ) {
            if ( ! in_array( $role, SectionRecipes::ROLES, true ) || [] === SectionRecipes::matchingNames( $role ) ) {
                $problems[] = "$label: role=$role has no recipe";
            }
        }
        if ( '' === $sectionHeading ) {
            return $problems;
        }
        $start = strpos( $text, "\n## " . $sectionHeading );
        if ( false === $start ) {
            return array_merge( $problems, [ "$label: no section ## $sectionHeading" ] );
        }
        $end     = strpos( $text, "\n## ", $start + 1 );
        $section = substr( $text, $start, false === $end ? null : $end - $start );
        // A segment starts at a line that opens with a bold title, numbered or bulleted or not.
        $parts = preg_split( '/\n(?=(?:\d+\. |- )?\*\*)/', $section ) ?: [];
        $seen  = 0;
        foreach ( array_slice( $parts, 1 ) as $segment ) {
            if ( 1 !== preg_match( '/^(?:\d+\. |- )?\*\*([^*]+)\*\*/', $segment, $t ) ) {
                continue;
            }
            $keys = array_values( array_filter( array_keys( $map ), static fn ( string $k ): bool => str_contains( $t[1], $k ) ) );
            // "Feature" is also inside "Features"-free titles only; prefer the longest key when two match.
            usort( $keys, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
            if ( [] === $keys ) {
                $problems[] = "$label: segment '" . $t[1] . "' has no role map entry";
                continue;
            }
            ++$seen;
            $allowed = $map[ $keys[0] ];
            foreach ( self::recipeMentions( $segment ) as $name ) {
                $role = (string) ( SectionRecipes::tags( $name )['role'] ?? '' );
                if ( in_array( $name, $names, true ) && ! in_array( $role, $allowed, true ) ) {
                    $problems[] = "$label: `$name` (role $role) offered for '" . $t[1] . "'";
                }
            }
            foreach ( self::roleMentions( $segment ) as $role ) {
                if ( ! in_array( $role, $allowed, true ) ) {
                    $problems[] = "$label: role=$role offered for '" . $t[1] . "'";
                }
            }
        }
        if ( 0 === $seen ) {
            $problems[] = "$label: no segment found in ## $sectionHeading";
        } elseif ( $expectedSegments > 0 && $seen !== $expectedSegments ) {
            $problems[] = "$label: $seen segments matched in ## $sectionHeading, expected $expectedSegments";
        }

        return $problems;
    }

    public function testTheLandingGuideFlowOffersExistingRecipesThatFitEachStage(): void
    {
        $this->assertSame( [], self::problems( 'landing', LandingGuide::markdown(), 'The conversion flow', self::STAGE_ROLES, self::LANDING_SEGMENTS ) );
    }

    public function testTheSiteGuidePageTypesOfferExistingRecipesThatFitEachPage(): void
    {
        $this->assertSame( [], self::problems( 'site', SiteGuide::markdown(), '1. Plan the page set', self::PAGE_ROLES, self::SITE_SEGMENTS ) );
    }

    public function testEveryRecipeNamedInTheStyleGuideAndItsTopicsExists(): void
    {
        $problems = self::problems( 'style', StyleGuide::markdown(), '', [] );
        foreach ( StyleGuide::topics() as $topic ) {
            $problems = array_merge( $problems, self::problems( 'topic ' . $topic, StyleGuide::markdown( $topic ), '', [] ) );
        }
        $problems = array_merge( $problems, self::problems( 'catalog', SectionRecipes::catalog(), '', [] ) );
        $this->assertSame( [], $problems );
    }

    public function testTheLandingFlowNamesEveryStageItNeeds(): void
    {
        $md = LandingGuide::markdown();
        foreach ( [ 'role=hero', 'role=proof', 'role=stats', 'role=testimonials', 'role=process', 'role=pricing', 'role=faq', 'role=cta', 'role=transition' ] as $role ) {
            $this->assertStringContainsString( '`' . $role . '`', $md );
        }
        foreach ( [ 'faq-accordion', 'faq-two-column', 'faq-toggle-list', 'pricing-three-tier', 'pricing-two-plan-highlight', 'pricing-comparison-table', 'cta-band', 'cta-split-form', 'cta-card-over-image', 'cta-countdown', 'transition-wave-band', 'transition-overlap-panel' ] as $name ) {
            $this->assertStringContainsString( '`' . $name . '`', $md );
        }
        $this->assertMatchesRegularExpression( '/`cta-countdown`[^.]*only[^.]*real deadline/', preg_replace( '/\s+/', ' ', $md ) ?? '' );
        $this->assertStringNotContainsString( '`card-grid-3`', $md, 'the old pointer for pains and steps is gone' );
        $this->assertStringNotContainsString( '`card-grid-4`', $md );
    }

    public function testTheSiteGuideNamesThePageTypesAndTheirRecipes(): void
    {
        $md = SiteGuide::markdown();
        foreach ( [ 'team-grid', 'founder-note', 'values-list', 'story-timeline', 'contact-details-form', 'contact-location-form', 'gallery-masonry-style', 'portfolio-featured-grid', 'before-after-compare' ] as $name ) {
            $this->assertStringContainsString( '`' . $name . '`', $md );
        }
        foreach ( [ 'Home', 'About', 'Services', 'Pricing', 'Portfolio', 'FAQ', 'Contact' ] as $page ) {
            $this->assertMatchesRegularExpression( '/^- \*\*' . $page . '\b/m', $md, $page . ' has a bullet' );
        }
    }

    // ---- the helper catches wrong pointers (negative tests) ----

    public function testAWrongOrUnknownPointerIsReported(): void
    {
        $md = LandingGuide::markdown();
        $this->assertStringContainsString( '`faq-accordion`', $md );
        $wrongRole = str_replace( '`faq-accordion`', '`card-grid-3`', $md );
        $this->assertContains( "landing: `card-grid-3` (role features) offered for 'Objection handling / FAQ — remove friction before the ask.'", self::problems( 'landing', $wrongRole, 'The conversion flow', self::STAGE_ROLES ) );
        $unknown = str_replace( '`faq-accordion`', '`faq-zigzag`', $md );
        $this->assertContains( 'landing: `faq-zigzag` is not a recipe', self::problems( 'landing', $unknown, 'The conversion flow', self::STAGE_ROLES ) );
        $badRole = str_replace( '`role=faq`', '`role=pricing`', $md );
        $this->assertContains( "landing: role=pricing offered for 'Objection handling / FAQ — remove friction before the ask.'", self::problems( 'landing', $badRole, 'The conversion flow', self::STAGE_ROLES ) );
        $this->assertContains( 'x: role=nope has no recipe', self::problems( 'x', 'Use `role=nope`.', '', [] ) );
        $unmapped = "## Flow\n1. **Mystery stage** `hero-cta`\n";
        $this->assertSame( [ "x: segment 'Mystery stage' has no role map entry", 'x: no segment found in ## Flow' ], self::problems( 'x', "\n" . $unmapped, 'Flow', self::STAGE_ROLES ) );
        $this->assertSame( [], self::problems( 'x', "\n## Flow\n1. **Hero — first** `hero-cta` `role=hero`\n", 'Flow', self::STAGE_ROLES ), 'a fitting pointer passes' );
    }

    public function testAMentionWithoutBackticksOrASingleWordNameIsChecked(): void
    {
        $md = LandingGuide::markdown();
        $plain = str_replace( '`faq-accordion`', 'faq-accordion', $md );
        $this->assertSame( [], self::problems( 'landing', $plain, 'The conversion flow', self::STAGE_ROLES, self::LANDING_SEGMENTS ), 'a fitting name without backticks passes' );
        $wrongPlain = str_replace( '`faq-accordion`', 'card-grid-3', $md );
        $this->assertContains( "landing: `card-grid-3` (role features) offered for 'Objection handling / FAQ — remove friction before the ask.'", self::problems( 'landing', $wrongPlain, 'The conversion flow', self::STAGE_ROLES ) );
        $single = str_replace( '`faq-accordion`', '`slider`', $md );
        $role   = (string) SectionRecipes::tags( 'slider' )['role'];
        $this->assertContains( "landing: `slider` (role $role) offered for 'Objection handling / FAQ — remove friction before the ask.'", self::problems( 'landing', $single, 'The conversion flow', self::STAGE_ROLES ) );
        $typo = str_replace( '`faq-accordion`', '`faq-accordon`', $md );
        $this->assertContains( 'landing: `faq-accordon` is not a recipe', self::problems( 'landing', $typo, 'The conversion flow', self::STAGE_ROLES ) );
        // Not a mention: a block name, a longer word, a single word in prose.
        $this->assertSame( [], self::problems( 'x', "\n## Flow\n1. **FAQ** use divi/contact-form, cta-band-like layouts, a slider\n", 'Flow', self::STAGE_ROLES ) );
        // A segment lost to a formatting change is caught by the count.
        $merged = (string) preg_replace( '/\n(\d+\. )?\*\*Pricing/', ' Pricing', $md, 1 );
        $this->assertNotSame( $md, $merged );
        $this->assertContains( 'landing: 10 segments matched in ## The conversion flow, expected 11', self::problems( 'landing', $merged, 'The conversion flow', self::STAGE_ROLES, self::LANDING_SEGMENTS ) );
    }
}
