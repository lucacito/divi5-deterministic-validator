<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';

/**
 * Size budgets of the type adapter (plan docs/superpowers/plans/2026-10-24-type-adapter.md, Task 0).
 *
 * The numbers were measured at main 77abc24. Everything the type adapter does not own must not move; what it
 * owns (one generated Type line per language topic, one typography sentence) may only grow within its cap.
 * Task 2 tightened the language topics to an exact equality; Task 3 tightened the typography topic to an exact equality too.
 */
final class LanguageTypeBudgetTest extends TestCase
{
    /** Bytes of each `design-language:<id>` topic before the Type line (luxe: 2,758 at 77abc24 less the 2 B its "0.1em" -> "3px" prose fix removed). */
    private const BEFORE_LANGUAGE_TOPICS = [
        'editorial'       => 1818,
        'glass-dark'      => 2151,
        'organic-warm'    => 2146,
        'tech-mono'       => 2273,
        'playful-pastel'  => 2477,
        'corporate-trust' => 2581,
        'soft-saas'       => 3258,
        'bold-vibrant'    => 3019,
        'brutalist-flat'  => 2550,
        'luxe'            => 2756,
    ];

    /** tech-mono's section_padding prose was reworded by the rhythm adapter (plan B, Task 2): "the same padding on every section" -> "every ordinary section has the same padding". */
    private const TECH_MONO_PROSE_DELTA = 10;

    /** wording gaps T4 (plan 2026-10-25-wording-gaps), Ruling 5: brutalist-flat "a 6px black line" -> "a strong black line" (+3 B), luxe's two "1px line in the accent colour" phrases -> "(the kit's accent rule)" (-8 and -10 B). */
    private const RULING_5_DELTA = [ 'brutalist-flat' => 3, 'luxe' => -18 ];

    /** wording gaps T7 (third blind round, card fill): glass-dark no longer calls its translucent tint the card colour (the card adapter fills
     * cards with `surface`): the three colour lines become the standard `surface (cards)` line (-187 B), and the prose rewords
     * "translucent cards" -> "layered cards" (-4), the shadow's semi-transparent background -> the surface colour (-17), and the
     * rhythm's and cta's "translucent " dropped (-12, -12): -232 B. The design-languages index carries the summary (-4 B: 2592 -> 2588). */
    // wording fixes (review): glass-dark's card border reworded "1px border at about 12% white" -> "hairline border" (-14 B): -232 -> -246.
    // brutalist-flat (review fix): the rhythm no longer describes a hover colour (-31 B) and the cta says "a single button" (-45 B): -76.
    private const TASK_7_DELTA = [ 'glass-dark' => -246, 'brutalist-flat' => -76 ];

    private const BEFORE_TYPOGRAPHY = 7496;

    private const TYPOGRAPHY_SENTENCE_MAX = 450;

    private const TOPIC_BOUND = 10000;

    public function testTheDefaultGuideAndTheTopicDescriptionDoNotMove(): void
    {
        self::assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide has no headroom to spare' );
        self::assertSame( 272, strlen( StyleGuide::topicParameterDescription() ), 'one topic name added: kit-patterns (wording gaps T4 (plan 2026-10-25-wording-gaps), Ruling 2: 258 -> 272)' );
    }

    public function testTheKitTopicsDoNotMove(): void
    {
        // carriers adapter: kit-rules 8997 -> 9598 (Ruling 8), style-kits 8444 -> 8178 (Ruling 7), controls 8331 -> 8348 (Ruling 10), then 9105 (object-values paragraph, round 2), then 9271 (render-fatal sweep: 9105 + 166 B, the generated slide/pricing-table keys and the plain-string sentence).
        self::assertSame( 9589, strlen( StyleGuide::markdown( 'kit-rules' ) ) ); // wording fixes (review): the tag width reword (a double at least 3px) moved kit-rules 9585 -> 9589; // wording gaps T4 (plan 2026-10-25-wording-gaps): 9598 -> 9585 (Ruling 8, within the 9,600 pin).
        self::assertSame( 8178, strlen( StyleGuide::markdown( 'style-kits' ) ) );
        // render-fatal sweep: 9105 -> 9271 (+166 B = the longer generated object-values paragraph, StyleGuideObjectValuesTest).
        // wording gaps T4 (plan 2026-10-25-wording-gaps): 9271 -> 9355 (+84 B = StyleGuide::controlsFormClause(), spec gap 14).
        self::assertSame( 9355, strlen( StyleGuide::markdown( 'controls' ) ) );
    }

    /**
     * Plan B adds the Section spacing line and the tech-mono reword (rhythm adapter, Task 2); each topic still
     * grows by exactly the generated lines the data produces.
     */
    public function testEachLanguageTopicGrowsByExactlyItsTypeLine(): void
    {
        self::assertSame( array_keys( self::BEFORE_LANGUAGE_TOPICS ), DesignLanguages::ids(), 'the budget table lists every language' );
        foreach ( self::BEFORE_LANGUAGE_TOPICS as $id => $before ) {
            $now      = strlen( StyleGuide::markdown( 'design-language:' . $id ) );
            $typeLine = DesignLanguages::typeLine( (array) DesignLanguages::get( $id ) );
            self::assertLessThanOrEqual( DesignLanguages::GENERATED_LINE_MAX, strlen( $typeLine ), "design-language:$id Type line is over its cap" );
            $spaceLine = DesignLanguages::sectionSpaceLine( (array) DesignLanguages::get( $id ) );
            self::assertLessThanOrEqual( DesignLanguages::GENERATED_LINE_MAX, strlen( $spaceLine ), "design-language:$id Section spacing line is over its cap" );
            $delta = 'tech-mono' === $id ? self::TECH_MONO_PROSE_DELTA : 0;
            // wording gaps T4 (plan 2026-10-25-wording-gaps): each topic also gains its generated Kit line (Ruling 4) and one newline, and brutalist-flat and luxe the Ruling 5 rewords (+3, -18 B).
            $kitLine = DesignLanguages::kitLine( (array) DesignLanguages::get( $id ) );
            self::assertLessThanOrEqual( DesignLanguages::GENERATED_LINE_MAX, strlen( $kitLine ), "design-language:$id Kit line is over its cap" );
            $delta  += strlen( $kitLine ) + 1 + ( self::RULING_5_DELTA[ $id ] ?? 0 ) + ( self::TASK_7_DELTA[ $id ] ?? 0 );
            self::assertSame( $before + strlen( $typeLine ) + 1 + strlen( $spaceLine ) + 1 + $delta, $now, "design-language:$id must grow by exactly its Type line, its Section spacing line and one newline each (plus the tech-mono reword)" );
            self::assertLessThanOrEqual( self::TOPIC_BOUND, $now );
        }
    }

    public function testTheTypographyTopicGrowsByExactlyItsSentence(): void
    {
        $now      = strlen( StyleGuide::markdown( 'typography' ) );
        $sentence = strlen( StyleGuide::typographyLanguageSentence() );
        self::assertGreaterThan( 0, $sentence, 'the typography topic names the language type' );
        self::assertLessThanOrEqual( self::TYPOGRAPHY_SENTENCE_MAX, $sentence );
        self::assertSame( self::BEFORE_TYPOGRAPHY + $sentence, $now, 'typography grows by exactly typographyLanguageSentence()' );
        self::assertLessThanOrEqual( 9500, $now );
    }
}
