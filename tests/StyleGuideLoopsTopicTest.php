<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens', 'BlockAttrs', 'LanguageAdapters', 'LanguageResolver' ] as $loopsTestClass ) {
    if ( is_file( __DIR__ . '/../wp-plugin/src/' . $loopsTestClass . '.php' ) ) {
        require_once __DIR__ . '/../wp-plugin/src/' . $loopsTestClass . '.php';
    }
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';

/**
 * The `loops` topic teaches the Loop Builder. Its example must validate, every token it names must be one Divi 5.15.1 lists
 * (fixtures/shapes/loop-variables-5.15.1.txt, read from DynamicContentOptions on the real site), and it stays within the bound.
 */
final class StyleGuideLoopsTopicTest extends TestCase
{
    private static function topic(): string
    {
        return StyleGuide::markdown('loops');
    }

    public function testTheTopicIsServedAndListedAndWithinTheBound(): void
    {
        $this->assertContains('loops', StyleGuide::topics());
        $this->assertStringContainsString('loops', StyleGuide::topicParameterDescription());
        $this->assertLessThanOrEqual(290, strlen(StyleGuide::topicParameterDescription()), 'the schema allows 300 characters');
        $this->assertLessThanOrEqual(10000, strlen(self::topic()), 'a topic stays within 10,000 bytes');
        $this->assertStringStartsWith('# Loops', self::topic());
    }

    public function testTheExampleBlocksValidate(): void
    {
        $this->assertSame(1, preg_match_all('/```\n(.*?)\n```/s', self::topic(), $m), 'one fenced example');
        $result = LayoutGate::check($m[1][0]);
        $this->assertTrue($result->isValid(), 'the example the topic hands the AI passes the checker: ' . json_encode(array_map(static fn($v) => $v->toArray(), $result->violations())));
        $this->assertStringContainsString('"enable":"on"', $m[1][0]);
    }

    public function testEveryTokenNamedIsOneDiviLists(): void
    {
        $live = array_map(static fn($l) => explode("\t", $l)[0], file(dirname(__DIR__) . '/fixtures/shapes/loop-variables-5.15.1.txt', FILE_IGNORE_NEW_LINES));
        preg_match_all('/`(loop_[a-z_]+)`/', self::topic(), $m);
        $this->assertNotSame([], $m[1]);
        foreach (array_unique($m[1]) as $name) {
            if (str_starts_with($name, 'loop_post_meta_key_') && 'loop_post_meta_key_manual_custom_field' !== $name) { // a custom field's key, not a variable name
                continue;
            }
            $this->assertContains($name, $live, "the topic names $name, which Divi 5.15.1 does not list");
        }
    }

    public function testTheDefaultGuideNamesTheTopic(): void
    {
        $this->assertStringContainsString('loops', StyleGuide::markdown());
    }
}
