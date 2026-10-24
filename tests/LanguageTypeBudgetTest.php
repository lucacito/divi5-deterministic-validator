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
 * Tasks 2 and 3 tighten the "at most" checks to exact equalities once the generated text exists.
 */
final class LanguageTypeBudgetTest extends TestCase
{
    /** Bytes of each `design-language:<id>` topic before the Type line. */
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
        'luxe'            => 2758,
    ];

    private const BEFORE_TYPOGRAPHY = 7496;

    /** A generated Type line (<= 260 B) plus the newline that ends it. */
    private const LANGUAGE_LINE_GROWTH_MAX = 261;

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

    public function testEachLanguageTopicGrowsByAtMostOneGeneratedLine(): void
    {
        self::assertSame( array_keys( self::BEFORE_LANGUAGE_TOPICS ), DesignLanguages::ids(), 'the budget table lists every language' );
        foreach ( self::BEFORE_LANGUAGE_TOPICS as $id => $before ) {
            $now = strlen( StyleGuide::markdown( 'design-language:' . $id ) );
            self::assertLessThanOrEqual( $before + self::LANGUAGE_LINE_GROWTH_MAX, $now, "design-language:$id grew by more than one Type line" );
            self::assertGreaterThanOrEqual( $before, $now, "design-language:$id shrank" );
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
