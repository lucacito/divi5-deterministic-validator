<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens' ] as $wordingGapsBudgetClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $wordingGapsBudgetClass . '.php';
}

/**
 * Size budgets of the wording-gaps branch (plan docs/superpowers/plans/2026-10-25-wording-gaps.md, Task 0).
 *
 * Measured at feat/wording-gaps 1d51dc9 (main's render-fatal sweep merged in). Every number equals the plan's table except
 * `controls`, which the sweep grew from 9,105 B to 9,271 B (its pin is the plan's <= 9,500 bound, so the baseline is recorded
 * here and pinned by CarrierBudgetTest). Task 3b re-pinned the kit topics; Task 4 re-pinned the description (258 -> 272,
 * Ruling 2), added the `kit-patterns` topic with its own bound, and re-pinned the ten design-language topics (a generated Kit line
 * each, plus the Ruling 5 rewords for brutalist-flat and luxe). A re-pin edits the numbers below on purpose, with the reason in
 * the commit message; nothing here is a baseline file.
 */
final class WordingGapsBudgetTest extends TestCase
{
    /** Bytes of the ten design-language topics at 1d51dc9, before Task 4 (the Kit line, Ruling 4; the Ruling 5 rewords). */
    private const LANGUAGE_TOPIC_SIZES = [
        'editorial'       => 2281,
        'glass-dark'      => 2613,
        'organic-warm'    => 2607,
        'tech-mono'       => 2744,
        'playful-pastel'  => 2890,
        'corporate-trust' => 3041,
        'soft-saas'       => 3720,
        'bold-vibrant'    => 3480,
        'brutalist-flat'  => 3012,
        'luxe'            => 3219,
    ];

    /**
     * Task 4, Ruling 5: the bytes the rewords in design-languages.json change. brutalist-flat "a 6px black line" -> "a strong
     * black line" (+3); luxe "(1px line in the accent colour)" -> "(the kit's accent rule)" (-8) and "(a 1px line in the accent
     * colour)" -> "(the kit's accent rule)" (-10).
     */
    private const RULING_5_DELTA = [ 'brutalist-flat' => 3, 'luxe' => -18 ];

    /** wording gaps T7 (third blind round, card fill): glass-dark no longer calls its translucent tint the card colour (the card adapter fills
     * cards with `surface`): the three colour lines become the standard `surface (cards)` line (-187 B), and the prose rewords
     * "translucent cards" -> "layered cards" (-4), the shadow's semi-transparent background -> the surface colour (-17), and the
     * rhythm's and cta's "translucent " dropped (-12, -12): -232 B. The design-languages index carries the summary (-4 B: 2592 -> 2588). */
    // wording fixes (review): glass-dark's card border reworded "1px border at about 12% white" -> "hairline border" (-14 B): -232 -> -246.
    // brutalist-flat (review fix): the rhythm no longer describes a hover colour (-31 B) and the cta says "a single button" (-45 B): -76.
    private const TASK_7_DELTA = [ 'glass-dark' => -246, 'brutalist-flat' => -76 ];

    /** Bytes of every other topic the wording-gaps plan does not name (they must not move). */
    private const UNTOUCHED_TOPIC_SIZES = [
        'design-languages' => 2588, // wording gaps T7: 2592 -> 2588 (glass-dark summary "translucent" -> "layered")
        'backgrounds'      => 8894,
        'dividers'         => 8869,
        'motion'           => 9800,
        'hover-states'     => 5637,
        'layout-advanced'  => 9973,
        'layout-sizing'    => 9972,
        'typography'       => 7943,
        'imagery'          => 6506,
        'toggles'          => 5834,
        'forms'            => 6474,
        'composition'      => 8706,
        'style-kits'       => 8178,
    ];

    public function testTheDefaultGuideIsExactlyTheSameSize(): void
    {
        $this->assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide neither grows nor shrinks (the swap is same-length)' );
    }

    public function testTheTopicDescriptionIsPinned(): void
    {
        // Task 4 (Ruling 2): 258 -> 272 characters (", kit-patterns"), and every <= 260 pin raised to <= 290 (the schema bound is 300).
        $this->assertSame( 272, strlen( StyleGuide::topicParameterDescription() ) );
    }

    public function testKitRulesAndControlsStayWithinTheirBounds(): void
    {
        // Task 4: kit-rules 9,598 -> 9,585 (Step 4 trims (a) and (b) paid for the new band, accordion, tag and pointer wording),
        // controls 9,271 -> 9,355 (the generated contact-form clause, spec gap 14).
        $this->assertLessThanOrEqual( 9600, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'Ruling 8' );
        $this->assertLessThanOrEqual( 9500, strlen( StyleGuide::markdown( 'controls' ) ), 'controls: 9,271 B at 1d51dc9, 9,355 B after Task 4' );
        $this->assertLessThanOrEqual( 6000, strlen( StyleGuide::markdown( 'kit-patterns' ) ), 'kit-patterns (Ruling 1): 5,049 B at Task 4, 5,427 B at Task 7' );
    }

    public function testNoStyleKitTopicExceedsTheStopBound(): void
    {
        $max = 0;
        foreach ( StyleKits::ids() as $id ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            $this->assertLessThanOrEqual( 9950, $len, "$id: STOP above 9,950 B (Ruling 12)" );
            $max = max( $max, $len );
        }
        $this->assertSame( 9556, $max, 'the largest kit topic is neo-brutal (Task 3b: 9,599 -> 9,556 with its own default language)' );
    }

    public function testTheDesignLanguageTopicsAreAtTheirMeasuredSizes(): void
    {
        // Task 4: each topic grows by exactly its generated Kit line and one newline, plus the Ruling 5 rewords.
        foreach ( self::LANGUAGE_TOPIC_SIZES as $id => $size ) {
            $kitLine = DesignLanguages::kitLine( (array) DesignLanguages::get( $id ) );
            $this->assertLessThanOrEqual( DesignLanguages::GENERATED_LINE_MAX, strlen( $kitLine ), $id );
            $this->assertSame( $size + strlen( $kitLine ) + 1 + ( self::RULING_5_DELTA[ $id ] ?? 0 ) + ( self::TASK_7_DELTA[ $id ] ?? 0 ), strlen( StyleGuide::markdown( 'design-language:' . $id ) ), "design-language:$id" );
        }
    }

    public function testEveryOtherTopicIsAtItsMeasuredSize(): void
    {
        foreach ( self::UNTOUCHED_TOPIC_SIZES as $topic => $size ) {
            $this->assertSame( $size, strlen( StyleGuide::markdown( $topic ) ), $topic );
        }
    }

    public function testThePinsCoverEveryServedTopic(): void
    {
        $named = [ 'kit-rules', 'controls', 'kit-patterns' ];
        foreach ( StyleGuide::topics() as $topic ) {
            if ( in_array( $topic, $named, true ) || str_starts_with( $topic, 'style-kit:' ) ) {
                continue;
            }
            if ( str_starts_with( $topic, 'design-language:' ) ) {
                $this->assertArrayHasKey( substr( $topic, strlen( 'design-language:' ) ), self::LANGUAGE_TOPIC_SIZES, $topic . ' has no size pin' );
                continue;
            }
            $this->assertArrayHasKey( $topic, self::UNTOUCHED_TOPIC_SIZES, $topic . ' has no size pin (a new topic needs its own bound)' );
        }
    }
}
