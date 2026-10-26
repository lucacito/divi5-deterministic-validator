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
     * The TOTAL number of differing leaves per class (review fix 3), exact: measured 2026-10-26 with the classifier's D1
     * narrowed to texts and sliders and D3 to groups (the spec section 6 table). Lowering one is a deliberate edit with
     * the new measurement; raising one is a STOP (an extra wrong leaf).
     */
    private const MATRIX_ONE_LEAVES = [ 'D1' => 1337, 'D2' => 960, 'D3' => 216, 'D4' => 38, 'D5' => 0, 'STRUCTURE' => 0, 'UNCLASSIFIED' => 0 ];

    private const CROSS_LEAVES = [ 'D1' => 747, 'D2' => 600, 'D3' => 192, 'D4' => 138, 'D5' => 6, 'STRUCTURE' => 0, 'UNCLASSIFIED' => 0 ];

    /** How many of the seeded pairs the suite switches between (Step 4 of the plan: 12 cut to 8 for the time budget). */
    public const CROSS_PAIRS = 8;

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
        $heading = str_replace( 'wp:divi/text', 'wp:divi/heading', $page );
        $this->assertSame( [ DesignDiffClassifier::UNCLASSIFIED ], DesignDiffClassifier::classify( str_replace( 'Inter', 'Lora', $heading ), $heading ), 'D1 is a text or slider font only' );
        $col = str_replace( 'divi/group', 'divi/column', $grp );
        $this->assertSame( [ 'D4' ], DesignDiffClassifier::classify( str_replace( '#111111', '#222222', $col ), $col ), 'D3 is a group fill only (a column fill is a kit card path)' );
        $two = str_replace( '"family":"Inter"', '"family":"Inter","color":"#111111"', $page );
        $this->assertSame( [ 'D1' => 1, 'D2' => 0, 'D3' => 0, 'D4' => 0, 'D5' => 0, 'STRUCTURE' => 0, 'UNCLASSIFIED' => 1 ], DesignDiffClassifier::leafTally( [], DesignDiffClassifier::details( str_replace( [ 'Inter', '#111111' ], [ 'Lora', '#222222' ], $two ), $two ) ) );

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
        // Leaves, not switches (review fix 3): an extra wrong leaf inside an already classified switch moves a total.
        // D1 = 1,242 text body + 21 text h4 + 32 slider body + 42 slider title fonts; D2 = 240 flush images x 4 corners;
        // D3 = 216 group fills; D4 = 9 panels x 4 padding sides + 2 hover frame colours.
        $this->assertSame( self::MATRIX_ONE_LEAVES, $r['leaves'] );
        $this->assertSame( [
            'D1' => self::D1_RECIPES,
            'D2' => [ 'gallery-masonry-style', 'team-grid' ],
            'D3' => [ 'pricing-comparison-table', 'proof-logo-wall' ],
            'D4' => [ 'contact-location-form' ],
        ], $r['recipes'], 'each class only where the spec names it' );
    }

    /**
     * Matrix 2: 71 recipes x the first 8 of the 12 seeded (language, kit) designs to each other = 4,544 switches.
     *
     * Reduced from 12 pairs (10,224 switches) by plan Task 6 Step 4: with 12 pairs the whole Pro suite took 237.6 s of
     * the 240 s budget (measured 2026-10-26), with 8 about 211 s. The 12-pair figures, measured with this classifier
     * before the cut: 9,512 identical (plan 9,533: minus 27 slider switches, the accepted tie, plus 6 identity switches,
     * the shortcut), D1 540, D2 64, D3 42 + 12 with D4 + 2 with D5, D4 48, D5 4, no unclassified difference.
     */
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
        foreach ( array_slice( self::SEEDED_PAIRS, 0, self::CROSS_PAIRS ) as [ $l, $k ] ) {
            $designs[ "$l/$k" ] = Design::make( $l, $k );
        }
        $n       = 0;
        $same    = 0;
        $firsts  = [];
        $combos  = [];
        $recipes = [];
        $bad     = [];
        $leaves  = [];
        foreach ( SectionRecipes::names() as $recipe ) {
            $built = [];
            foreach ( $designs as $id => $d ) {
                $built[ $id ] = DesignSwitchMatrixOne::build( $recipe, $d );
            }
            foreach ( $designs as $a => $from ) {
                foreach ( $designs as $b => $to ) {
                    ++$n;
                    $details  = DesignDiffClassifier::details( DesignSwitch::run( $built[ $a ], $from, $to )->content, $built[ $b ] );
                    $classes  = DesignDiffClassifier::classesOf( $details );
                    $leaves   = DesignDiffClassifier::leafTally( $leaves, $details );
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
        $this->assertSame( 71 * 8 * 8, $n );
        $this->assertSame( [], $bad, 'every difference is one of D1-D5' );
        $this->assertSame( 4199, $same );
        $this->assertCaps( [ 'D1' => 249, 'D2' => 30, 'D3' => 32, 'D4' => 30, 'D5' => 4 ], DesignDiffClassifier::tally( $firsts ) );
        // D1 = 687 text body + 12 text h4 + 24 + 24 slider fonts; D2 = 150 x 4 corners; D3 = 192 group fills;
        // D4 = 30 x 4 card radius corners + 12 frame widths + 6 frame styles; D5 = 6 blocks.
        $this->assertSame( self::CROSS_LEAVES, $leaves );
        ksort( $combos, SORT_STRING );
        $this->assertCaps( [ 'D1' => 249, 'D2' => 30, 'D3' => 24, 'D3+D4' => 6, 'D3+D5' => 2, 'D4' => 30, 'D5' => 4 ], $combos );
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
