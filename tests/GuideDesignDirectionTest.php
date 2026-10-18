<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LandingGuide;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\SiteGuide;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/LandingGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SiteGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';

/**
 * The guides teach composition: pick a design language first and state it, vary the rhythm,
 * and act on advisories.design_variety. They no longer say "commit to ONE aesthetic".
 */
class GuideDesignDirectionTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function guides(): array
    {
        return [
            'style'   => [ StyleGuide::markdown() ],
            'landing' => [ LandingGuide::markdown() ],
            'site'    => [ SiteGuide::markdown() ],
        ];
    }

    /** @dataProvider guides */
    public function testPicksALanguageFirstAndStatesIt( string $md ): void
    {
        $this->assertStringContainsString( 'design-languages', $md );
        $flat = self::flat( $md );
        $this->assertMatchesRegularExpression( '/pick a design language first[^.]*?(choose|read)[^.]*?\bstate it\b/i', $flat );
    }

    /** @dataProvider guides */
    public function testStatesTheRhythmRules( string $md ): void
    {
        foreach ( [
            'never the same layout in adjacent sections',
            'alternate background treatment',
            'at most one card grid per three sections',
            'give the first section a focal element (image, gradient or large type)',
        ] as $rule ) {
            $this->assertStringContainsString( $rule, $md );
        }
    }

    /** @dataProvider guides */
    public function testActsOnTheVarietyAdvisory( string $md ): void
    {
        $flat = self::flat( $md );
        $this->assertStringContainsString( 'advisories.design_variety', $flat );
        $this->assertMatchesRegularExpression( '/`try` has names|`try` names|`try` if it has any/', $flat );
        $this->assertStringContainsString( 'it never blocks a save, and the page is saved as it is', $flat );
        $this->assertMatchesRegularExpression( '/save again/i', $flat );
    }

    /** @dataProvider guides */
    public function testEmptyTryIsNotTreatedAsAReplacementList( string $md ): void
    {
        $this->assertMatchesRegularExpression( "/`try` is empty, follow the finding's `message`/", self::flat( $md ) );
    }

    public function testDesignLanguageIsTaughtTogetherWithName(): void
    {
        foreach ( [ LandingGuide::markdown(), SiteGuide::markdown() ] as $md ) {
            $this->assertMatchesRegularExpression( '/`name` plus (the site\'s )?`design_language`/', self::flat( $md ) );
            $this->assertStringContainsString( 'works only together with `name`', self::flat( $md ) );
        }
        $this->assertStringContainsString( 'used together with name', self::flat( SectionRecipes::catalog() ) );
    }

    private static function flat( string $md ): string
    {
        return (string) preg_replace( '/\s+/', ' ', $md );
    }

    public function testStyleGuideNoLongerSaysOneAestheticWithoutThePointer(): void
    {
        $md = StyleGuide::markdown();
        $this->assertStringNotContainsString( 'Commit to ONE clear aesthetic direction', $md );
        $this->assertStringNotContainsString( 'ONE clear aesthetic', $md );
    }

    public function testLandingGuideNoLongerSaysOneAesthetic(): void
    {
        $this->assertStringNotContainsString( 'One aesthetic + one accent', LandingGuide::markdown() );
    }

    public function testSiteGuideLocksOneLanguageAcrossPages(): void
    {
        $md = SiteGuide::markdown();
        $this->assertMatchesRegularExpression( '/ONE design language/', $md );
        $this->assertStringContainsString( 'vary', strtolower( $md ) );
    }

    public function testRecipeCatalogMentionsDesignLanguage(): void
    {
        $catalog = SectionRecipes::catalog();
        $this->assertStringContainsString( 'design_language', $catalog );
        $this->assertStringContainsString( 'design-languages', $catalog );
        $this->assertStringContainsString( 'default look', $catalog );
    }

    public function testStyleGuideStaysWithinTheGrowthCap(): void
    {
        // Ruling 5: 27908 original + 1200 across Tasks 7 and 10.
        $this->assertLessThanOrEqual( 29108, strlen( StyleGuide::markdown() ) );
    }
}
