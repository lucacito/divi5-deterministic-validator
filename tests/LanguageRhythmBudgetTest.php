<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';

/**
 * Size budgets of the rhythm adapter (plan docs/superpowers/plans/2026-10-24-rhythm-adapter.md, Task 0).
 *
 * The numbers were measured at feat/type-adapter 2b40d59 (the merged state). Everything the rhythm adapter does
 * not own must not move; what it owns (one generated Section spacing line per language topic, plus the tech-mono
 * reword) grows each language topic by exactly that (Task 2 tightened the check to an equality).
 */
final class LanguageRhythmBudgetTest extends TestCase
{
    /** tech-mono's section_padding prose was reworded in Task 2: "the same padding on every section" -> "every ordinary section has the same padding". */
    private const TECH_MONO_PROSE_DELTA = 10;

    /** Bytes of each `design-language:<id>` topic before the Section spacing line. */
    private const BEFORE_LANGUAGE_TOPICS = [
        'editorial'       => 2026,
        'glass-dark'      => 2359,
        'organic-warm'    => 2354,
        'tech-mono'       => 2481,
        'playful-pastel'  => 2667,
        'corporate-trust' => 2788,
        'soft-saas'       => 3466,
        'bold-vibrant'    => 3227,
        'brutalist-flat'  => 2775,
        'luxe'            => 2963,
    ];

    private const TOPIC_BOUND = 10000;

    public function testNothingButTheLanguageTopicsMoves(): void
    {
        self::assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide has no headroom to spare' );
        self::assertSame( 258, strlen( StyleGuide::topicParameterDescription() ), 'no new topic name is added' );
        self::assertSame( 7945, strlen( StyleGuide::markdown( 'typography' ) ) );
        self::assertSame( 8997, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        self::assertSame( 8444, strlen( StyleGuide::markdown( 'style-kits' ) ) );
        self::assertSame( 8331, strlen( StyleGuide::markdown( 'controls' ) ) );
        self::assertSame( 2592, strlen( StyleGuide::markdown( 'design-languages' ) ) );
        self::assertSame( 9972, strlen( StyleGuide::markdown( 'layout-sizing' ) ) );
    }

    public function testEachLanguageTopicGrowsByExactlyItsSectionSpacingLine(): void
    {
        self::assertSame( array_keys( self::BEFORE_LANGUAGE_TOPICS ), DesignLanguages::ids(), 'the budget table lists every language' );
        foreach ( self::BEFORE_LANGUAGE_TOPICS as $id => $before ) {
            $now = strlen( StyleGuide::markdown( 'design-language:' . $id ) );
            $line = DesignLanguages::sectionSpaceLine( (array) DesignLanguages::get( $id ) );
            self::assertNotSame( '', $line, "$id has a Section spacing line" );
            self::assertLessThanOrEqual( DesignLanguages::GENERATED_LINE_MAX, strlen( $line ), "$id: the line is over its cap" );
            $delta = 'tech-mono' === $id ? self::TECH_MONO_PROSE_DELTA : 0;
            self::assertSame( $before + strlen( $line ) + 1 + $delta, $now, "$id: must grow by exactly its Section spacing line and one newline" );
            self::assertLessThanOrEqual( self::TOPIC_BOUND, $now, "$id: over the topic bound" );
        }
    }
}
