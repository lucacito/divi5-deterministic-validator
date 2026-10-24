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
 * Task 2 tightened the language topics to an exact equality; Task 3 tightens the typography topic.
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

    private const BEFORE_TYPOGRAPHY = 7496;

    private const TYPOGRAPHY_SENTENCE_MAX = 450;

    private const TOPIC_BOUND = 10000;

    public function testTheDefaultGuideAndTheTopicDescriptionDoNotMove(): void
    {
        self::assertSame( 28975, strlen( StyleGuide::markdown() ), 'the default guide has no headroom to spare' );
        self::assertSame( 258, strlen( StyleGuide::topicParameterDescription() ), 'no new topic name is added' );
    }

    public function testTheKitTopicsDoNotMove(): void
    {
        self::assertSame( 8997, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        self::assertSame( 8444, strlen( StyleGuide::markdown( 'style-kits' ) ) );
        self::assertSame( 8331, strlen( StyleGuide::markdown( 'controls' ) ) );
    }

    public function testEachLanguageTopicGrowsByExactlyItsTypeLine(): void
    {
        self::assertSame( array_keys( self::BEFORE_LANGUAGE_TOPICS ), DesignLanguages::ids(), 'the budget table lists every language' );
        foreach ( self::BEFORE_LANGUAGE_TOPICS as $id => $before ) {
            $now      = strlen( StyleGuide::markdown( 'design-language:' . $id ) );
            $typeLine = DesignLanguages::typeLine( (array) DesignLanguages::get( $id ) );
            self::assertLessThanOrEqual( DesignLanguages::GENERATED_LINE_MAX, strlen( $typeLine ), "design-language:$id Type line is over its cap" );
            self::assertSame( $before + strlen( $typeLine ) + 1, $now, "design-language:$id must grow by exactly its Type line and one newline" );
            self::assertLessThanOrEqual( self::TOPIC_BOUND, $now );
        }
    }

    public function testTheTypographyTopicGrowsByAtMostOneSentence(): void
    {
        $now = strlen( StyleGuide::markdown( 'typography' ) );
        self::assertGreaterThanOrEqual( self::BEFORE_TYPOGRAPHY, $now );
        self::assertLessThanOrEqual( self::BEFORE_TYPOGRAPHY + self::TYPOGRAPHY_SENTENCE_MAX, $now );
        self::assertLessThanOrEqual( 9500, $now );
    }
}
