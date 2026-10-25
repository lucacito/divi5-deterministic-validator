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
        // carriers adapter: kit-rules 8997 -> 9598 (Ruling 8), style-kits 8444 -> 8178 (Ruling 7), controls 8331 -> 8348 (Ruling 10), then 9105 (object-values paragraph, round 2).
        self::assertSame( 9598, strlen( StyleGuide::markdown( 'kit-rules' ) ) );
        self::assertSame( 8178, strlen( StyleGuide::markdown( 'style-kits' ) ) );
        self::assertSame( 9105, strlen( StyleGuide::markdown( 'controls' ) ) );
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
