<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\SchemaRules;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The "Values that must be objects" paragraph of the controls topic is generated from the validator's own tables
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
        // The live-proven render fatals (src/VerifiedContentTypes.php) are named too, except modules no guide teaches.
        foreach ( ( new SchemaRules() )->renderFatalKeys()['object'] as $module => $keys ) {
            if ( in_array( $module, StyleGuide::OBJECT_VALUES_NOT_TAUGHT, true ) ) {
                $this->assertStringNotContainsString( '`' . $module . '`', $text, "$module is not taught, so not named" );
                continue;
            }
            foreach ( $keys as $key ) {
                ++$count;
                $this->assertMatchesRegularExpression( '/`' . preg_quote( $module, '/' ) . '` [^;]*`' . preg_quote( $key, '/' ) . '`/', $text, "$module $key is named" );
            }
        }
        $this->assertGreaterThan( 16, $count );
        $this->assertStringNotContainsString( 'divi/video', $text, 'video is guarded by the validator but never taught' );
        $this->assertStringContainsString( Validator::E_SCALAR_WHERE_OBJECT, $text, 'it names the code the save is refused with' );
    }

    public function testThePlainStringSentenceIsGeneratedFromTheScalarTable(): void
    {
        $text   = StyleGuide::objectValuesParagraph();
        $scalar = ( new SchemaRules() )->renderFatalKeys()['scalar'];
        $this->assertStringContainsString( Validator::E_OBJECT_WHERE_SCALAR, $text );
        foreach ( StyleGuide::SCALAR_VALUE_EXAMPLES as $module ) {
            $this->assertArrayHasKey( $module, $scalar, "$module is an example only while the sweep proved its keys" );
            foreach ( $scalar[ $module ] as $key ) {
                $this->assertMatchesRegularExpression( '/`' . preg_quote( $module, '/' ) . '` [^;]*`' . preg_quote( $key, '/' ) . '`/', $text, "$module $key is named" );
            }
        }
        $planted = StyleGuide::objectValuesParagraph( [ 'divi/planted' => [ [ 'thing', false, true ] ] ], [], [ 'divi/heading' => [ 'planted' ] ] );
        $this->assertStringContainsString( '`divi/heading` `planted`', $planted );
        $this->assertStringNotContainsString( 'OBJECT_WHERE_SCALAR', StyleGuide::objectValuesParagraph( [ 'divi/planted' => [ [ 'thing', false, true ] ] ], [], [] ), 'no example, no sentence' );
    }

    public function testOnlyModulesNoGuideTeachesAreLeftOut(): void
    {
        // Exact rule: a module is left out iff it is video, or the sweep alone guards it and no recipe and no topic uses it.
        $recipes = (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' );
        $topics  = '';
        foreach ( StyleGuide::topics() as $topic ) {
            $topics .= str_replace( StyleGuide::objectValuesParagraph(), '', StyleGuide::markdown( $topic ) );
        }
        $topics .= str_replace( StyleGuide::objectValuesParagraph(), '', StyleGuide::markdown() );
        $handWritten = [];
        foreach ( ( new SchemaRules() )->contentKeyRules() as $module => $keys ) {
            foreach ( $keys as [ , , $mustBeObject ] ) {
                if ( $mustBeObject ) {
                    $handWritten[ $module ] = true;
                }
            }
        }
        $expected = [ 'divi/video' ];
        foreach ( array_keys( ( new SchemaRules() )->renderFatalKeys()['object'] ) as $module ) {
            $used = 1 === preg_match( '#wp:' . preg_quote( $module, '#' ) . '[ /]#', $recipes ) || 1 === preg_match( '#' . preg_quote( $module, '#' ) . '(?![a-z-])#', $topics );
            if ( ! isset( $handWritten[ $module ] ) && ! $used ) {
                $expected[] = $module;
            }
        }
        sort( $expected );
        $actual = StyleGuide::OBJECT_VALUES_NOT_TAUGHT;
        sort( $actual );
        $this->assertSame( $expected, $actual );
    }

    public function testAPlantedTableIsFollowedAndAnEmptyOneSaysNothing(): void
    {
        $text = StyleGuide::objectValuesParagraph( [ 'divi/planted' => [ [ 'thing', false, true ], [ 'scalarkey', false, false ] ] ], [ 'divi/planted' => [ 'other' ] ], [] );
        $this->assertStringContainsString( '`divi/planted` `thing`, `other`', $text, 'both tables, one entry per module' );
        $this->assertStringNotContainsString( 'scalarkey', $text, 'a key that may be a scalar is not listed' );
        $this->assertSame( '', StyleGuide::objectValuesParagraph( [ 'divi/heading' => [ [ 'title', true, false ] ] ], [], [] ) );
        $this->assertSame( '', StyleGuide::objectValuesParagraph( [], [], [ 'divi/heading' => [ 'title' ] ] ), 'no object key: nothing at all' );
        $this->assertStringContainsString( '`divi/planted` `other`', StyleGuide::objectValuesParagraph( [], [ 'divi/planted' => [ 'other' ] ], [] ) );
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
        // render-fatal sweep: 757 -> 923 B (slide `image`, pricing-table `button` and the plain-string sentence); bound 900 -> 1,000.
        $this->assertLessThanOrEqual( 1000, strlen( StyleGuide::objectValuesParagraph() ) );
        $this->assertLessThanOrEqual( 9500, strlen( $controls ), 'the controls topic keeps 500 B of headroom' );
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
