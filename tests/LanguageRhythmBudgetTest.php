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
 * not own must not move; what it owns (one generated Section spacing line per language topic) may only grow
 * within its cap. Task 2 tightens the language topics to an exact equality.
 */
final class LanguageRhythmBudgetTest extends TestCase
{
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

    /** One generated line is at most 260 B (DesignLanguages::GENERATED_LINE_MAX) plus its newline. */
    private const LINE_MAX_WITH_NEWLINE = 261;

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

    public function testEachLanguageTopicGrowsByAtMostOneGeneratedLine(): void
    {
        self::assertSame( array_keys( self::BEFORE_LANGUAGE_TOPICS ), DesignLanguages::ids(), 'the budget table lists every language' );
        foreach ( self::BEFORE_LANGUAGE_TOPICS as $id => $before ) {
            $now = strlen( StyleGuide::markdown( 'design-language:' . $id ) );
            self::assertLessThanOrEqual( $before + self::LINE_MAX_WITH_NEWLINE, $now, "$id: more than one generated line was added" );
            self::assertLessThanOrEqual( self::TOPIC_BOUND, $now, "$id: over the topic bound" );
        }
    }
}
