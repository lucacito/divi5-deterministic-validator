<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

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
 * here and pinned by CarrierBudgetTest). Green now; Task 3b re-pins the kit topics, Task 4 re-pins the description (258 -> 272,
 * Ruling 2), adds the `kit-patterns` topic with its own bound, and re-pins the ten design-language topics (a generated Kit line
 * each, plus the Ruling 5 rewords for brutalist-flat and luxe). A re-pin edits the numbers below on purpose, with the reason in
 * the commit message; nothing here is a baseline file.
 */
final class WordingGapsBudgetTest extends TestCase
{
    /** Bytes of the ten design-language topics at 1d51dc9 (Task 4 re-pins them: the Kit line, Ruling 4; the Ruling 5 rewords). */
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

    /** Bytes of every other topic the wording-gaps plan does not name (they must not move). */
    private const UNTOUCHED_TOPIC_SIZES = [
        'design-languages' => 2592,
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
        // Task 4 (Ruling 2): 258 -> 272 characters, and the pin in StyleGuideTopicsTest 260 -> 290.
        $this->assertSame( 258, strlen( StyleGuide::topicParameterDescription() ) );
    }

    public function testKitRulesAndControlsStayWithinTheirBounds(): void
    {
        $this->assertLessThanOrEqual( 9600, strlen( StyleGuide::markdown( 'kit-rules' ) ), 'Ruling 8' );
        $this->assertLessThanOrEqual( 9500, strlen( StyleGuide::markdown( 'controls' ) ), 'controls: 9,271 B at 1d51dc9' );
    }

    public function testNoStyleKitTopicExceedsTheStopBound(): void
    {
        $max = 0;
        foreach ( StyleKits::ids() as $id ) {
            $len = strlen( StyleGuide::markdown( 'style-kit:' . $id ) );
            $this->assertLessThanOrEqual( 9950, $len, "$id: STOP above 9,950 B (Ruling 12)" );
            $max = max( $max, $len );
        }
        $this->assertSame( 9599, $max, 'the largest kit topic is neo-brutal (Task 3b re-pins it)' );
    }

    public function testTheDesignLanguageTopicsAreAtTheirMeasuredSizes(): void
    {
        foreach ( self::LANGUAGE_TOPIC_SIZES as $id => $size ) {
            $this->assertSame( $size, strlen( StyleGuide::markdown( 'design-language:' . $id ) ), "design-language:$id" );
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
        $named = [ 'kit-rules', 'controls' ];
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
