<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * The `typography` topic's generated "Design language type" section (type adapter plan Task 3): it is built from
 * LanguageAdapters::APPLIED, TYPE_PATHS, TYPE_HEADING_LEVELS and TYPE_PROVEN, so no path in it is typed by hand, and it names
 * no number (the numbers are each language's Type line).
 */
class StyleGuideLanguageTypeTest extends TestCase
{
    private const MAX = 450;

    public function testTheTypographySentenceNamesTheAdaptersPaths(): void
    {
        $sentence = StyleGuide::typographyLanguageSentence();
        $this->assertStringStartsWith( "\n\n## Design language type\n\n", $sentence );
        $this->assertLessThanOrEqual( self::MAX, strlen( $sentence ) );
        foreach ( LanguageAdapters::TYPE_PATHS as $block => $path ) {
            $this->assertStringContainsString( '`' . $path . '.desktop.value`', $sentence, "$block's path" );
        }
        $registry = ShapeRegistry::load();
        $paths    = ShapePaths::fromGuideText( $sentence );
        $this->assertCount( 2, $paths, 'the two carrier paths and nothing else' );
        foreach ( $paths as $path ) {
            foreach ( [ 'weight', 'letterSpacing', 'capitalization' ] as $key ) {
                $this->assertTrue( $registry->covers( $path . '.' . $key ), "$path.$key is in the shape registry" );
            }
        }
        foreach ( LanguageAdapters::TYPE_HEADING_LEVELS as $role => $levels ) {
            $this->assertStringContainsString( '`' . $role . '`', $sentence );
            foreach ( $levels as $level ) {
                $this->assertStringContainsString( $level, $sentence );
            }
        }
        $this->assertSame( 1, substr_count( $sentence, 'capital letters' ) );
        $this->assertMatchesRegularExpression( '/only where the language[^.;]*capital letters/', $sentence, 'capitalisation is said to come only where the language names capital letters' );
        $this->assertStringContainsString( 'it reaches 900 and 400 px (rendered)', $sentence, 'the cascade decisions are true: the widths lt-*-cascade rendered' );
        $this->assertStringNotContainsString( 'tablet and phone', $sentence, 'StyleGuideTypographyTopicTest: no device claim beyond the rendered widths' );
        $this->assertStringContainsString( 'topic=design-language:<id>', $sentence );
        $this->assertDoesNotMatchRegularExpression( '/\b[1-9]00\b|-?\dpx/', str_replace( 'it reaches 900 and 400 px (rendered)', '', $sentence ), 'no weight or letter spacing number is named (the two widths are the rendered ones)' );
        $this->assertStringNotContainsString( 'aied', $sentence, 'the marker is recipe-internal' );

        $this->assertSame( '', StyleGuide::typographyLanguageSentence( [] ), 'no carrier: no sentence' );
        $this->assertSame( $sentence, StyleGuide::typographyLanguageSentence( LanguageAdapters::APPLIED['type'] ) );
        $textOnly = StyleGuide::typographyLanguageSentence( [ 'divi/text' => [ 'eyebrow' ] ] );
        $this->assertStringContainsString( 'content.decoration.bodyFont.body.font.desktop.value', $textOnly );
        $this->assertStringNotContainsString( 'title.decoration.font.font', $textOnly );
    }

    public function testTheCascadeWordingFollowsTheDecisions(): void
    {
        $planted = array_merge( LanguageAdapters::TYPE_PROVEN, [ 'heading-cascade' => false ] );
        $s       = StyleGuide::typographyLanguageSentence( null, $planted );
        $this->assertStringNotContainsString( '900 and 400 px', $s );
        $this->assertStringContainsString( '`title.decoration.font.font.{bp}.value`', $s );
        $this->assertStringContainsString( '`content.decoration.bodyFont.body.font.desktop.value`', $s );
        $this->assertLessThanOrEqual( self::MAX, strlen( $s ) );
    }

    public function testTheTypographyTopicEndsWithTheSentence(): void
    {
        $text = new \ReflectionMethod( StyleGuide::class, 'typographyTopicText' );
        $this->assertSame( $text->invoke( null ) . StyleGuide::typographyLanguageSentence(), StyleGuide::markdown( 'typography' ) );
        $this->assertStringEndsWith( StyleGuide::typographyLanguageSentence(), StyleGuide::markdown( 'typography' ) );
        $this->assertSame( 7496, strlen( (string) $text->invoke( null ) ), 'the hand-written topic did not move' );
    }
}
