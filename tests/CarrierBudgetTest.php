<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens' ] as $carrierBudgetClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $carrierBudgetClass . '.php';
}

/**
 * Size budgets of the carriers adapter (plan docs/superpowers/plans/2026-10-24-carriers-adapter.md, Task 0).
 *
 * Measured at feat/carriers-adapter f1a370a; every number equals the plan's table. Everything the carriers adapter does not
 * own must not move: the default guide, the topic description and every style-kit:<id> topic. Task 3 tightened kit-rules,
 * the catalog and controls to exact equalities (testTheCarrierBudgets).
 */
final class CarrierBudgetTest extends TestCase
{
    /** Bytes of each style-kit:<id> topic at f1a370a (largest: neo-brutal, 9,599). */
    private const KIT_TOPIC_SIZES = [
        'quiet-solid'         => 8189,
        'glass-edge'          => 8146,
        'warm-pill'           => 8212,
        'hairline-mono'       => 8073,
        'chunky-pill'         => 8081,
        'steady-solid'        => 8200,
        'soft-rounded'        => 8075,
        'heavy-block'         => 8183,
        'raw-frame'           => 8079,
        'gilt-hairline'       => 8010,
        'soft-solid'          => 8148,
        'outline-2'           => 8102,
        'warm-pill-soft'      => 9176,
        'chunky-pill-offset'  => 9246,
        'hairline-dashed'     => 8056,
        'underline-link'      => 8736,
        'neo-brutal'          => 9556,
        'double-frame'        => 8163,
        'soft-lift'           => 9172,
        'ghost-swap'          => 8064,
        'dotted-pill'         => 8164,
        'hairline-frame-caps' => 8137,
        'offset-frame'        => 9277,
        'soft-hairline-caps'  => 8162,
        'thick-frame-caps'    => 8146,
        'thick-rounded-caps'  => 8176,
        'rimmed-solid'        => 7982,
        'flat-square'         => 8260,
        'block-caps'          => 8254,
        'pill-caps'           => 8302,
        'outline-pill'        => 8131,
        'outline-soft'        => 8110,
        'thick-frame'         => 8085,
        'raised-square'       => 9382,
        'offset-block-caps'   => 9547,
        'text-link'           => 8072,
        'text-link-caps'      => 8105,
        'underlined-label'    => 8849,
        'pressable'           => 9532,
        'hairline-halo'       => 9251,
    ];

    public function testTheDefaultGuideAndTheTopicDescriptionDoNotMove(): void
    {
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
        $this->assertSame( 272, strlen( StyleGuide::topicParameterDescription() ), 'one topic name added: kit-patterns (wording gaps T4 (plan 2026-10-25-wording-gaps), Ruling 2: 258 -> 272)' );
    }

    public function testTheKitTopicsDoNotMove(): void
    {
        $this->assertCount( 40, self::KIT_TOPIC_SIZES );
        $this->assertSame( array_keys( self::KIT_TOPIC_SIZES ), StyleKits::ids(), 'the same 40 kits, in order' );
        $max = 0;
        foreach ( self::KIT_TOPIC_SIZES as $id => $size ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            $this->assertSame( $size, $len, "$id: a kit topic does not move" );
            $max = max( $max, $len );
        }
        $this->assertSame( 9556, $max, 'the largest kit topic (neo-brutal; 9,599 -> 9,556 in the wording-gaps Task 3b: samples in the kit\'s own language)' );
    }

    public function testKitRulesStaysWithinItsPin(): void
    {
        $this->assertLessThanOrEqual( 9600, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'kit-rules (Ruling 8: pin 9,000 -> 9,600; the real bound is 10,000)' );
    }

    public function testTheCatalogProjectionStaysWithinItsBound(): void
    {
        $property = new \ReflectionProperty( StyleKits::class, 'cache' );
        $real     = array_map( static fn ( string $id ): array => (array) StyleKits::get( $id ), StyleKits::ids() );
        try {
            $kits = $real;
            for ( $n = 1; count( $kits ) < 50; $n++ ) {
                $clone          = $real[ $n % count( $real ) ];
                $clone['id']    = 'extra-kit-' . $n;
                $clone['title'] = 'Extra kit ' . $n;
                $kits[]         = $clone;
            }
            $property->setValue( null, $kits );
            $this->assertCount( 50, StyleKits::ids() );
            $this->assertLessThanOrEqual( 9950, strlen( StyleGuide::markdown( 'style-kits' ) ), 'the 50-kit catalog' );
        } finally {
            StyleKits::clearCache();
        }
    }

    /**
     * Task 3: the exact sizes of the three topics the carriers adapter changes, each derived from what it adds. kit-rules =
     * the 8,997 B at the base + the growth of the applied sentence (370 B at the base) + the change of the not-applied sentence
     * (74 B at the base, Ruling 9 and two nouns fewer) + the More carriers paragraph; the catalog prints the noun-only sentence
     * (Ruling 7); controls grows only by its generated sentence (Ruling 10).
     */
    public function testTheCarrierBudgets(): void
    {
        $oldApplied = [
            'divi/button'       => [ 'control', 'control-secondary' ],
            'divi/group'        => [ 'card', 'panel', 'rule', 'rule-strong', 'chip' ],
            'divi/column'       => [ 'card', 'panel', 'rule', 'rule-strong' ],
            'divi/accordion'    => [ 'item', 'item-rule' ],
            'divi/toggle'       => [ 'item', 'item-rule' ],
            'divi/contact-form' => [ 'form' ],
            'divi/divider'      => [ 'rule', 'rule-strong' ],
            'divi/icon'         => [ 'chip' ],
        ];
        $this->assertSame( 370, strlen( StyleGuide::appliedSentence( $oldApplied ) ), 'the applied sentence at the base' );
        $this->assertSame( 74, strlen( StyleGuide::notAppliedSentence( $oldApplied ) ), 'the not-applied sentence at the base' );
        $this->assertSame( '', StyleGuide::carrierRules( $oldApplied ), 'the base map has no More carriers paragraph' );
        // wording gaps T4 (plan 2026-10-25-wording-gaps): the base map's kit-rules moved 8,997 -> 8,984 with the new band, accordion, tag and pointer wording (Step 4).
        $this->assertSame( 8984, strlen( StyleGuide::kitRules( $oldApplied ) ), 'kit-rules at the base, regenerated from the base map' );

        $applied    = strlen( StyleGuide::appliedSentence() );
        $notApplied = strlen( StyleGuide::notAppliedSentence() );
        $carriers   = strlen( StyleGuide::carrierRules() );
        $this->assertSame( 442, $applied );
        $this->assertSame( 50, $notApplied, '"Every other module keeps the recipe\'s own styling."' );
        $this->assertSame( 553, $carriers );
        $this->assertLessThanOrEqual( 560, $carriers, 'Ruling 8: the More carriers paragraph is at most 560 B with its heading' );
        $kitRules = strlen( StyleGuide::markdown( 'kit-rules' ) );
        $this->assertSame( 8984 + ( $applied - 370 ) + ( $notApplied - 74 ) + $carriers, $kitRules );
        $this->assertSame( 9585, $kitRules ); // wording gaps T4 (plan 2026-10-25-wording-gaps): 9598 -> 9585 (Ruling 8, within the 9,600 pin).
        $this->assertLessThanOrEqual( 9600, $kitRules, 'Ruling 8: pin 9,000 -> 9,600' );

        $this->assertSame( 8178, strlen( StyleGuide::markdown( 'style-kits' ) ), 'Ruling 7: the catalog with the noun-only sentence (8,444 at the base)' );
        $this->assertSame( 8444 - 370 - 74 + strlen( StyleGuide::appliedNounsSentence() ) + $notApplied, strlen( StyleGuide::markdown( 'style-kits' ) ) );

        $controlsDelta = strlen( StyleGuide::controlsKitSentence() ) - strlen( StyleGuide::controlsKitSentence( $oldApplied ) );
        $this->assertSame( strlen( ' or `rule-accent`' ), $controlsDelta, 'Ruling 10: the divider marker list gains `rule-accent`, nothing else' );
        // wording gaps T4 (plan 2026-10-25-wording-gaps): plus the generated contact-form clause (spec gap 14).
        $this->assertSame( 8331 + $controlsDelta + strlen( StyleGuide::objectValuesParagraph() ) + strlen( StyleGuide::controlsFormClause() ), strlen( StyleGuide::markdown( 'controls' ) ), 'dogfood round 2: the object-values paragraph and the form clause are the only other growth' );
        // render-fatal sweep: 9105 -> 9271 (+166 B = the longer generated object-values paragraph, StyleGuideObjectValuesTest).
        // wording gaps T4 (plan 2026-10-25-wording-gaps): 9271 -> 9355 (+84 B = StyleGuide::controlsFormClause(), spec gap 14).
        $this->assertSame( 9355, strlen( StyleGuide::markdown( 'controls' ) ) );

        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide does not grow' );
        $this->assertSame( 272, strlen( StyleGuide::topicParameterDescription() ), 'one topic name added: kit-patterns (wording gaps T4 (plan 2026-10-25-wording-gaps), Ruling 2: 258 -> 272)' );
        foreach ( self::KIT_TOPIC_SIZES as $id => $size ) {
            $this->assertSame( $size, strlen( StyleGuide::markdown( 'style-kit:' . $id ) ), "$id: a kit topic does not move" );
        }
    }

    public function testTheRecipeFileDoesNotGrow(): void
    {
        $this->assertLessThanOrEqual( 652430, filesize( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), 'section-recipes.json (the migration only shrinks it)' );
    }
}
