<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Tests\Support\DesignDiffClassifier;
use AiEditorDivi5\Pro\Tests\Support\DesignSwitchMatrixOne;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/Support/DesignDiffClassifier.php';
require_once __DIR__ . '/Support/DesignSwitchMatrixOne.php';

/**
 * Design switch, Task 6: the headline proof (spec 2026-10-25-pro-design-switch-design.md sections 1, 6 and 9; plan
 * Task 6, controller Ruling 6). A page built from the recipe library under one design and switched to another is
 * byte-identical to the direct build under the target, or differs only in the named classes D1-D5, classified by the ONE
 * classifier `Support/DesignDiffClassifier`. An unclassified difference fails. Identical counts are pinned EXACTLY;
 * each class count (switches counted by their first class in D1..D5 order) is a shrink-only cap: lowering one is a
 * deliberate edit with the new measurement in the commit message, raising one is a STOP. No baseline file.
 *
 * Measured against the plan's prototype figures (proto2.php), two accepted, explained moves:
 * - the divi/slider tie (Task 5, accepted by the controller): the slider title font's library context is display 1 :
 *   eyebrow 1, and Task 4's STRICT majority leaves such a font `ambiguous` where the prototype took the first role, so the
 *   `slider` recipe joins D1 (23 switches in matrix 1, 27 in the seeded matrix);
 * - the identity shortcut (Task 5): `DesignSwitch::run()` returns the page unchanged when from == to, so the identity
 *   switches the prototype ran through the engine (1 D5 in matrix 1, 6 D4/D5 in the seeded matrix) are identical now.
 *   The engine path the shortcut skips is still proven: `DesignSwitchPropertiesTest::testIdentityThroughTheEngine`.
 */
class DesignSwitchMatrixTest extends TestCase
{
    /** The seeded (language, kit) pairs of the cross matrix: `mt_srand(7)`, then 12 x (language mt_rand(0, 9), kit mt_rand(0, 39)). */
    public const SEEDED_PAIRS = [
        [ 'corporate-trust', 'warm-pill-soft' ],
        [ 'glass-dark', 'soft-rounded' ],
        [ 'tech-mono', 'flat-square' ],
        [ 'bold-vibrant', 'hairline-halo' ],
        [ 'brutalist-flat', 'glass-edge' ],
        [ 'brutalist-flat', 'outline-soft' ],
        [ 'brutalist-flat', 'double-frame' ],
        [ 'organic-warm', 'soft-solid' ],
        [ 'soft-saas', 'thick-frame' ],
        [ 'tech-mono', 'pressable' ],
        [ 'soft-saas', 'quiet-solid' ],
        [ 'corporate-trust', 'outline-soft' ],
    ];

    /**
     * The recipes with an untyped text (or slider) font whose source family two roles share: the 19 of the spec plus
     * `slider` (the accepted tie).
     */
    private const D1_RECIPES = [
        'card-grid-3', 'founder-note', 'hero-gradient-stats', 'hero-spec-card', 'icon-values', 'pricing-three-tier',
        'pricing-two-plan-highlight', 'proof-press-quotes', 'proof-rating-strip', 'quote-over-image', 'quote-single-large',
        'slider', 'split-image-text', 'stats-big-captions', 'stats-big-numbers', 'steps-horizontal', 'steps-numbered',
        'story-timeline', 'testimonials-slider-quote', 'values-list',
    ];

    public function testTheClassifierNamesEveryKindOfDifference(): void
    {
        $page = '<!-- wp:divi/section {"a":{"b":"x"},"c":"y"} --><!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"family":"Inter"}}}}}}}} /--><!-- /wp:divi/section -->';
        $this->assertSame( [], DesignDiffClassifier::classify( $page, $page ) );
        $this->assertSame( [ 'D5' ], DesignDiffClassifier::classify( str_replace( '{"a":{"b":"x"},"c":"y"}', '{"c":"y","a":{"b":"x"}}', $page ), $page ) );
        $this->assertSame( [ 'D1' ], DesignDiffClassifier::classify( str_replace( 'Inter', 'Lora', $page ), $page ) );
        $this->assertSame( [ DesignDiffClassifier::UNCLASSIFIED ], DesignDiffClassifier::classify( str_replace( '"x"', '"z"', $page ), $page ) );
        $this->assertSame( [ DesignDiffClassifier::STRUCTURE ], DesignDiffClassifier::classify( $page . '<!-- wp:divi/text /-->', $page ) );
        $this->assertSame( [ DesignDiffClassifier::STRUCTURE ], DesignDiffClassifier::classify( str_replace( '><!-- wp:divi/text', '>hello<!-- wp:divi/text', $page ), $page ) );
        $this->assertSame( [ DesignDiffClassifier::STRUCTURE ], DesignDiffClassifier::classify( str_replace( 'wp:divi/text', 'wp:divi/heading', $page ), $page ) );

        $img = '<!-- wp:divi/image {"image":{"decoration":{"border":{"desktop":{"value":{"radius":{"topLeft":"0px"}}}}}}} /-->';
        $this->assertSame( [ 'D2' ], DesignDiffClassifier::classify( str_replace( '0px', '8px', $img ), $img ) );
        $grp = '<!-- wp:divi/group {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#111111"}}},"border":{"desktop":{"value":{"radius":{"topLeft":"4px"}}}}}}} /-->';
        $this->assertSame( [ 'D3' ], DesignDiffClassifier::classify( str_replace( '#111111', '#222222', $grp ), $grp ) );
        $this->assertSame( [ 'D4' ], DesignDiffClassifier::classify( str_replace( '"4px"', '"9px"', $grp ), $grp ), 'a card radius is a kit path' );
        $this->assertSame( [ DesignDiffClassifier::UNCLASSIFIED ], DesignDiffClassifier::classify( str_replace( '"4px"', '"9px"', str_replace( 'divi/group', 'divi/text', $grp ) ), str_replace( 'divi/group', 'divi/text', $grp ) ), 'a text carries no kit path' );

        $details = DesignDiffClassifier::details( str_replace( 'Inter', 'Lora', $page ), $page );
        $this->assertSame( [ [ 'block' => 1, 'key' => 'divi/text#0', 'name' => 'divi/text', 'path' => 'content.decoration.bodyFont.body.font.desktop.value.family', 'class' => 'D1' ] ], $details );
    }

    /** Matrix 1: 71 recipes x the ten (language, own kit) designs to each other = 7,100 switches. */
    public function testDefaultDesignsMatrix(): void
    {
        $r = DesignSwitchMatrixOne::result();
        $this->assertSame( 71 * 10 * 10, $r['switches'] );
        $this->assertSame( [], $r['unclassified'], 'every difference is one of D1-D5' );
        // Plan 6,571 (prototype): minus 23 slider switches (the accepted tie), plus 1 identity switch (the shortcut).
        $this->assertSame( 6549, $r['identical'] );
        $this->assertCaps( [ 'D1' => 458, 'D2' => 48, 'D3' => 36, 'D4' => 9, 'D5' => 0 ], DesignDiffClassifier::tally( $r['firsts'] ) );
        $this->assertSame( [ 'D1' => 458, 'D2' => 48, 'D3' => 36, 'D4' => 9 ], $r['combos'], 'no switch mixes two classes' );
        $this->assertSame( [
            'D1' => self::D1_RECIPES,
            'D2' => [ 'gallery-masonry-style', 'team-grid' ],
            'D3' => [ 'pricing-comparison-table', 'proof-logo-wall' ],
            'D4' => [ 'contact-location-form' ],
        ], $r['recipes'], 'each class only where the spec names it' );
    }

    /** Matrix 2: 71 recipes x 12 seeded (language, kit) designs to each other = 10,224 switches. */
    public function testSeededCrossMatrix(): void
    {
        mt_srand( 7 );
        $kits  = StyleKits::ids();
        $langs = DesignLanguages::ids();
        $drawn = [];
        for ( $i = 0; $i < 12; $i++ ) {
            $drawn[] = [ $langs[ mt_rand( 0, 9 ) ], $kits[ mt_rand( 0, count( $kits ) - 1 ) ] ];
        }
        mt_srand();
        $this->assertSame( self::SEEDED_PAIRS, $drawn, 'the draw is pinned literally: a PHP generator change cannot move it silently' );

        $designs = [];
        foreach ( self::SEEDED_PAIRS as [ $l, $k ] ) {
            $designs[ "$l/$k" ] = Design::make( $l, $k );
        }
        $n       = 0;
        $same    = 0;
        $firsts  = [];
        $combos  = [];
        $recipes = [];
        $bad     = [];
        foreach ( SectionRecipes::names() as $recipe ) {
            $built = [];
            foreach ( $designs as $id => $d ) {
                $built[ $id ] = DesignSwitchMatrixOne::build( $recipe, $d );
            }
            foreach ( $designs as $a => $from ) {
                foreach ( $designs as $b => $to ) {
                    ++$n;
                    $classes  = DesignDiffClassifier::classify( DesignSwitch::run( $built[ $a ], $from, $to )->content, $built[ $b ] );
                    $firsts[] = $classes[0] ?? null;
                    if ( [] === $classes ) {
                        ++$same;
                        continue;
                    }
                    $combo            = implode( '+', $classes );
                    $combos[ $combo ] = ( $combos[ $combo ] ?? 0 ) + 1;
                    foreach ( $classes as $c ) {
                        $recipes[ $c ][ $recipe ] = true;
                    }
                    if ( array_intersect( $classes, [ DesignDiffClassifier::UNCLASSIFIED, DesignDiffClassifier::STRUCTURE ] ) ) {
                        $bad[] = "$recipe $a>$b";
                    }
                }
            }
        }
        $this->assertSame( 71 * 12 * 12, $n );
        $this->assertSame( [], $bad, 'every difference is one of D1-D5' );
        // Plan 9,533 (prototype): minus 27 slider switches (the accepted tie), plus 6 identity switches (the shortcut).
        $this->assertSame( 9512, $same );
        $this->assertCaps( [ 'D1' => 540, 'D2' => 64, 'D3' => 56, 'D4' => 48, 'D5' => 4 ], DesignDiffClassifier::tally( $firsts ) );
        ksort( $combos, SORT_STRING );
        $this->assertCaps( [ 'D1' => 540, 'D2' => 64, 'D3' => 42, 'D3+D4' => 12, 'D3+D5' => 2, 'D4' => 48, 'D5' => 4 ], $combos );
        $this->assertSame( [ 'D1', 'D2', 'D3', 'D3+D4', 'D3+D5', 'D4', 'D5' ], array_keys( $combos ) );
        foreach ( $recipes as $c => $set ) {
            $recipes[ $c ] = array_keys( $set );
            sort( $recipes[ $c ], SORT_STRING );
        }
        ksort( $recipes, SORT_STRING );
        $this->assertSame( [
            'D1' => self::D1_RECIPES,
            'D2' => [ 'gallery-masonry-style', 'team-grid' ],
            'D3' => [ 'pricing-comparison-table', 'proof-logo-wall' ],
            'D4' => [ 'pricing-comparison-table', 'proof-logo-wall', 'stats-big-captions' ],
            'D5' => [ 'pricing-comparison-table', 'proof-logo-wall', 'stats-big-captions' ],
        ], $recipes );
    }

    /**
     * Each measured count is at or under its cap, and no class appears that has no cap.
     *
     * @param array<string,int> $caps
     * @param array<string,int> $measured
     */
    private function assertCaps( array $caps, array $measured ): void
    {
        foreach ( $measured as $class => $count ) {
            if ( 0 === $count ) {
                continue;
            }
            $this->assertArrayHasKey( $class, $caps, "class $class has no cap" );
            $this->assertLessThanOrEqual( $caps[ $class ], $count, "class $class over its shrink-only cap" );
        }
    }
}
