<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\SchemaRules;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The "Values that must be objects" paragraph of the controls topic is generated from the validator's own table
 * (a blind page-building run wrote a testimonial's `company` as a string and Divi answered HTTP 500), so it cannot
 * disagree with what the plugin refuses, and its example shapes are the shapes of the shipped recipes.
 */
final class StyleGuideObjectValuesTest extends TestCase
{
    public function testEveryObjectKeyOfTheValidatorTableIsNamed(): void
    {
        $text  = StyleGuide::objectValuesParagraph();
        $count = 0;
        foreach ( ( new SchemaRules() )->contentKeyRules() as $module => $keys ) {
            foreach ( $keys as [ $key, , $mustBeObject ] ) {
                if ( $mustBeObject && ! in_array( $module, StyleGuide::OBJECT_VALUES_NOT_TAUGHT, true ) ) {
                    ++$count;
                    $this->assertStringContainsString( '`' . $module . '`', $text, "$module is named" );
                    $this->assertStringContainsString( '`' . $key . '`', $text, "$module $key is named" );
                }
            }
        }
        $this->assertGreaterThan( 12, $count );
        $this->assertStringNotContainsString( 'divi/video', $text, 'video is guarded by the validator but never taught' );
        $this->assertStringContainsString( Validator::E_SCALAR_WHERE_OBJECT, $text, 'it names the code the save is refused with' );
    }

    public function testAPlantedTableIsFollowedAndAnEmptyOneSaysNothing(): void
    {
        $text = StyleGuide::objectValuesParagraph( [ 'divi/planted' => [ [ 'thing', false, true ], [ 'scalarkey', false, false ] ] ] );
        $this->assertStringContainsString( '`divi/planted` `thing`', $text );
        $this->assertStringNotContainsString( 'scalarkey', $text, 'a key that may be a scalar is not listed' );
        $this->assertSame( '', StyleGuide::objectValuesParagraph( [ 'divi/heading' => [ [ 'title', true, false ] ] ] ) );
        $this->assertSame( '', StyleGuide::objectValuesParagraph( [] ) );
    }

    public function testTheExampleShapesAreTheShapesOfTheShippedRecipes(): void
    {
        $shapes = [
            'divi/testimonial'  => [ 'testimonial', [ 'company' => [ 'text' ], 'portrait' => [ 'url', 'alt' ] ] ],
            'divi/contact-form' => [ 'contact-form', [ 'button' => [ 'text' ] ] ],
        ];
        $this->assertSame( array_keys( $shapes ), array_keys( StyleGuide::OBJECT_SHAPES ), 'every documented shape is checked here' );
        foreach ( $shapes as $module => [ $recipe, $keys ] ) {
            $raw = (string) SectionRecipes::rawMarkup( $recipe );
            $this->assertSame( 1, preg_match( '/<!-- wp:' . preg_quote( $module, '/' ) . ' (\{.*?\}) \/?-->/s', $raw, $m ), "$recipe holds a $module" );
            $attrs = json_decode( $m[1], true );
            $this->assertIsArray( $attrs );
            foreach ( $keys as $key => $fields ) {
                $value = $attrs[ $key ]['innerContent']['desktop']['value'] ?? null;
                $this->assertIsArray( $value, "$module $key is an object in the recipe" );
                foreach ( $fields as $field ) {
                    $this->assertArrayHasKey( $field, $value );
                    $this->assertStringContainsString( '"' . $field . '"', StyleGuide::OBJECT_SHAPES[ $module ][ $key ], "the example for $key shows $field" );
                }
                $this->assertSame( count( $fields ), preg_match_all( '/"[a-z]+":/', StyleGuide::OBJECT_SHAPES[ $module ][ $key ] ), "the example for $key shows exactly the recipe's fields" );
            }
        }
    }

    public function testTheControlsTopicEndsWithTheParagraphAndStaysUnderTheBound(): void
    {
        $controls = StyleGuide::markdown( 'controls' );
        $this->assertStringEndsWith( StyleGuide::objectValuesParagraph(), $controls );
        $this->assertLessThanOrEqual( 10000, strlen( $controls ) );
        $this->assertLessThanOrEqual( 900, strlen( StyleGuide::objectValuesParagraph() ) );
    }

    public function testEveryListedKeyIsReallyRefusedAsAString(): void
    {
        // the paragraph's promise ("the save is refused") is the validator's behaviour for each listed key
        $v = new Validator();
        foreach ( [
            [ 'divi/testimonial', 'company' ],
            [ 'divi/testimonial', 'portrait' ],
            [ 'divi/contact-form', 'button' ],
        ] as [ $module, $key ] ) {
            $block  = '<!-- wp:' . $module . ' {"' . $key . '":{"innerContent":{"desktop":{"value":"a string"}}},"builderVersion":"5.8.0"} /-->';
            $layout = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.8.0"} --><!-- wp:divi/row {"builderVersion":"5.8.0"} --><!-- wp:divi/column {"builderVersion":"5.8.0"} -->'
                . $block . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
            $codes = array_map( static fn ( $x ): string => $x->code(), $v->validate( json_encode( [ 'post_content' => $layout ] ) )->violations() );
            $this->assertContains( Validator::E_SCALAR_WHERE_OBJECT, $codes, "$module $key as a string is refused" );
        }
    }
}
