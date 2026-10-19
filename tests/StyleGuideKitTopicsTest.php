<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $kitTopicsTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $kitTopicsTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/**
 * Task 6 of the style kits plan: the guide topics `style-kits` (what a kit is, the catalog, how to choose) and
 * `style-kit:<id>` (one kit in words, plus a primary and a secondary button resolved by the real pipeline for the
 * `editorial` language).
 */
class StyleGuideKitTopicsTest extends TestCase
{
    private const TOPIC_BUDGET = 10000;

    /** @return list<string> */
    private static function kitTopics(): array
    {
        return array_map( static fn ( string $id ): string => 'style-kit:' . $id, StyleKits::ids() );
    }

    /** @return array<string,array{0:string}> */
    public static function everyKitTopic(): array
    {
        $cases = [ 'style-kits' => [ 'style-kits' ] ];
        foreach ( StyleKits::ids() as $id ) {
            $cases[ 'style-kit:' . $id ] = [ 'style-kit:' . $id ];
        }

        return $cases;
    }

    public function testTopicsListTheCatalogAndOneTopicPerKit(): void
    {
        $topics = StyleGuide::topics();
        $this->assertContains( 'style-kits', $topics );
        $this->assertGreaterThanOrEqual( 2, count( self::kitTopics() ) );
        foreach ( self::kitTopics() as $topic ) {
            $this->assertContains( $topic, $topics );
        }
        $this->assertSame( $topics, array_values( array_unique( $topics ) ), 'no topic is listed twice' );
    }

    /** @dataProvider everyKitTopic */
    public function testEachKitTopicIsServedWithinBudgetAndIsPlain( string $topic ): void
    {
        $text = StyleGuide::markdown( $topic );
        $this->assertStringNotContainsString( 'Unknown topic', $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( $text ) );
        $this->assertGreaterThan( 600, strlen( $text ), 'a real topic, not a stub' );
        $this->assertStringNotContainsString( 'aied', $text, 'no token and no kit marker ever reaches the AI' );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"/', $text, 'no custom CSS is ever taught' );
        $this->assertDoesNotMatchRegularExpression( '/<script|<style|javascript:/i', $text, 'no custom code in a topic' );
        $this->assertSame( $text, ( $this->mcp( [ 'topic' => $topic ] ) )[1], 'MCP serves the same text' );
        $this->assertSame( [ 'guide' => $text ], $this->rest( [ 'topic' => $topic ] )->get_data(), 'REST serves the same text' );
    }

    public function testTheCatalogTopicIntroducesKitsChoosesAndIsHonestAboutScope(): void
    {
        $text = StyleGuide::markdown( 'style-kits' );
        $this->assertStringStartsWith( '# Style kits', $text );
        foreach ( StyleKits::ids() as $id ) {
            $this->assertStringContainsString( '`' . $id . '`', $text );
            $this->assertStringContainsString( (string) StyleKits::get( $id )['summary'], $text, $id . ' summary comes from the catalog' );
            $this->assertStringContainsString( 'style-kit:' . $id, $text, 'points to the topic of ' . $id );
        }
        $this->assertStringContainsString( 'bundle of decisions', $text );
        $this->assertStringContainsString( 'design language', $text );
        $this->assertStringContainsString( 'style_kit', $text );
        $this->assertStringContainsString( 'get_section_recipes', $text );
        $this->assertMatchesRegularExpression( '/only (the )?buttons?/i', $text, 'the scope today is said honestly' );
        $this->assertMatchesRegularExpression( '/do(n\'t| not) mix/i', $text, 'one kit per page' );
        $this->assertSame( 1, preg_match_all( '/^# /m', $text ), 'one H1' );
    }

    public function testEachKitTopicStatesTheKitsDecisions(): void
    {
        foreach ( StyleKits::ids() as $id ) {
            $kit  = StyleKits::get( $id );
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertStringStartsWith( '# Style kit: ' . $kit['title'], $text );
            $this->assertStringContainsString( (string) $kit['summary'], $text );
            $this->assertStringContainsString( (string) $kit['radius']['control'], $text );
            $this->assertStringContainsString( (string) $kit['border']['width'], $text );
            $this->assertStringContainsString( 'accent band', $text );
            $this->assertStringContainsString( 'photo', $text );
            $this->assertStringContainsString( 'editorial', $text );
        }
    }

    public function testTheGuideIsHonestAboutWhatAKitAppliesToday(): void
    {
        $catalog = StyleGuide::markdown( 'style-kits' );
        $this->assertStringContainsString( 'are not kit-driven yet', $catalog );
        $this->assertStringNotContainsString( 'will follow later', $catalog );
        foreach ( StyleKits::ids() as $id ) {
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            $this->assertMatchesRegularExpression( '/card and media radius and card padding are recorded but NOT applied today/', $text, $id );
            $this->assertStringContainsString( "only the button's control values are", $text, $id );
            $this->assertStringNotContainsString( 'shadow and card padding are recorded', $text, $id . ': a button shadow IS applied now' );
        }
        $this->assertStringNotContainsString( 'shadow and card padding are listed', $catalog );
        $this->assertStringContainsString( 'card and media radius and card padding are listed', $catalog );
    }

    public function testEachKitTopicDescribesItsShadowLiftAndUnderlineTruthfully(): void
    {
        $seen = [];
        foreach ( StyleKits::ids() as $id ) {
            $kit    = (array) StyleKits::get( $id );
            $text   = StyleGuide::markdown( 'style-kit:' . $id );
            $pair   = $kit['shadow']['rest'] . '/' . $kit['shadow']['hover'];
            $lift   = 'lift' === $kit['hover'];
            $bottom = 'bottom' === ( $kit['border']['sides'] ?? 'all' );
            $expect = [
                'A soft shadow sits under the button'                => 'soft/soft' === $pair,
                'A hard offset shadow sits under the button'         => 'hard/hard' === $pair,
                'A soft shadow appears under the button on hover'    => 'none/soft' === $pair,
                'On hover the button lifts 4px'                      => $lift,
                'Only the bottom border is drawn: an underline'      => $bottom,
            ];
            foreach ( $expect as $sentence => $applies ) {
                if ( $applies ) {
                    $seen[ $sentence ] = true;
                    $this->assertStringContainsString( $sentence, $text, $id );
                } else {
                    $this->assertStringNotContainsString( $sentence, $text, $id );
                }
            }
            $effects = 'none/none' !== $pair || $lift || $bottom;
            $this->assertSame( $effects, str_contains( $text, '## Shadow, lift and underline' ), $id );
        }
        $this->assertCount( 5, $seen, 'the shipped kits use every effect' );
    }

    public function testATextOnlyButtonIsNamedExactlyWhenTheKitDrawsOne(): void
    {
        $seen = 0;
        foreach ( StyleKits::ids() as $id ) {
            $kit  = (array) StyleKits::get( $id );
            $text = StyleGuide::markdown( 'style-kit:' . $id );
            foreach ( [ 'control' => 'primary', 'control-secondary' => 'secondary' ] as $role => $name ) {
                $d        = KitAdapters::patch( 'divi/button', $role, 'light', $kit )['button']['decoration'];
                $textOnly = KitAdapters::TRANSPARENT === $d['background']['desktop']['value']['color'] && '0px' === $d['border']['desktop']['value']['styles']['all']['width']
                    && ! isset( $d['border']['desktop']['value']['styles']['bottom'] );
                $sentence = sprintf( 'The %s button is text-only', $name );
                if ( $textOnly ) {
                    ++$seen;
                    $this->assertStringContainsString( $sentence, $text, "$id: its $name button has no fill and no border" );
                } else {
                    $this->assertStringNotContainsString( $sentence, $text, "$id: its $name button has a fill or a border" );
                }
            }
        }
        $this->assertGreaterThan( 0, $seen, 'soft-solid\'s borderless secondary is text-only' );
    }

    /** @return list<array{0:string,1:array<string,mixed>}> block comment text and its decoded attributes, in document order */
    private function buttons( string $topic ): array
    {
        preg_match_all( '/<!-- wp:divi\/button (\{.*?\}) \/-->/', StyleGuide::markdown( $topic ), $m );
        $out = [];
        foreach ( $m[0] as $i => $block ) {
            $attrs = json_decode( $m[1][ $i ], true );
            $this->assertIsArray( $attrs, 'block attributes decode' );
            $out[] = [ $block, $attrs ];
        }

        return $out;
    }

    public function testEachKitTopicCarriesAPrimaryAndASecondaryButtonThatValidateAndAreRegistered(): void
    {
        $registry = ShapeRegistry::load();
        foreach ( StyleKits::ids() as $id ) {
            $buttons = $this->buttons( 'style-kit:' . $id );
            $this->assertCount( 2, $buttons, $id . ': a primary and a secondary button, no more' );
            foreach ( $buttons as $i => [ $block, $attrs ] ) {
                $page   = '<!-- wp:divi/section {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/row {"builderVersion":"5.0.0-public-beta.1"} --><!-- wp:divi/column {"builderVersion":"5.0.0-public-beta.1"} -->' . $block . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
                $result = ( new Validator() )->validateContent( $page );
                $this->assertTrue( $result->isValid(), "$id button $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
                $this->assertTrue( LayoutGate::check( $page )->isValid(), "$id button $i passes the plugin gate (custom-code policy included)" );
                $this->assertArrayNotHasKey( 'aied', $attrs, 'the marker is resolved away' );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "$id button $i uses an unregistered path $path" );
                }
                $this->assertNotSame( '', (string) ( $attrs['button']['innerContent']['desktop']['value']['text'] ?? '' ), 'the button has placeholder text' );
            }
        }
    }

    public function testTheSnippetsAreTheResolvedKitInTheEditorialLanguage(): void
    {
        $editorial = \AiEditorDivi5\WP\DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $editorial );
        foreach ( StyleKits::ids() as $id ) {
            $kit = StyleKits::get( $id );
            [ [ , $primary ], [ , $secondary ] ] = $this->buttons( 'style-kit:' . $id );
            foreach ( [ $primary, $secondary ] as $attrs ) {
                $deco = $attrs['button']['decoration'];
                $this->assertSame( array_fill_keys( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ], $kit['radius']['control'] ), $deco['border']['desktop']['value']['radius'], 'the kit radius is written as the four corners' );
                $this->assertSame( $kit['text']['weight'], $deco['font']['font']['desktop']['value']['weight'] );
                $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/i', $deco['font']['font']['desktop']['value']['color'], 'colours are the language values' );
                // The button padding is the MODULE's spacing (the button element's spacing is dead on divi/button).
                $this->assertSame(
                    [ 'top' => $kit['space']['control']['vertical'], 'right' => $kit['space']['control']['horizontal'], 'bottom' => $kit['space']['control']['vertical'], 'left' => $kit['space']['control']['horizontal'] ],
                    $attrs['module']['decoration']['spacing']['desktop']['value']['padding'],
                    'the kit padding is written on module.decoration.spacing'
                );
                $this->assertArrayNotHasKey( 'spacing', $deco, 'never on button.decoration.spacing' );
                $this->assertSame( [ 'display' => 'block' ], $attrs['module']['decoration']['layout']['desktop']['value'], 'the module layout survives' );
            }
            $this->assertSame( 'solid' === $kit['fill'] ? $editorial['colors'][ $kit['color'] ] ?? $editorial['colors'][ str_replace( '-', '_', $kit['color'] ) ] : KitAdapters::TRANSPARENT, $primary['button']['decoration']['background']['desktop']['value']['color'], 'the primary fill follows the kit' );
            // A ghost kit's secondary is its outline treatment: both transparent, told apart by the outline.
            $look = static fn ( array $a ): array => [ $a['button']['decoration']['background']['desktop']['value']['color'], $a['button']['decoration']['border']['desktop']['value']['styles']['all']['width'] ];
            $this->assertNotSame( $look( $primary ), $look( $secondary ), 'primary and secondary differ in their fill or their outline' );
            if ( 'ghost' !== $kit['fill'] ) {
                $this->assertNotSame(
                    $primary['button']['decoration']['background']['desktop']['value']['color'],
                    $secondary['button']['decoration']['background']['desktop']['value']['color'],
                    'primary and secondary differ in their fill'
                );
            }
        }
    }

    public function testUnknownKitIdIsAnsweredLikeAnUnknownLanguage(): void
    {
        foreach ( [ 'style-kit:nope', 'style-kit:', 'style-kit:SOFT-SOLID', "style-kit:soft-solid\n# Injected" ] as $bad ) {
            $text = StyleGuide::markdown( $bad );
            $this->assertStringStartsWith( '# Unknown topic', $text );
            foreach ( self::kitTopics() as $topic ) {
                $this->assertStringContainsString( '`' . $topic . '`', $text );
            }
            $this->assertStringNotContainsString( 'Divi 5 Layout Authoring Guide', $text );
            $this->assertSame( 1, preg_match_all( '/^# /m', $text ), 'a hostile id adds no heading' );
        }
    }

    public function testTheDefaultGuideNamesTheKitTopicsAndStaysUnderItsCeiling(): void
    {
        $text = StyleGuide::markdown();
        $last = substr( $text, (int) strrpos( $text, "\n\n" ) + 2 );
        $named = explode( ', ', substr( $last, strlen( 'Topics: call get_style_guide with topic=<name>: ' ), -1 ) );
        $this->assertContains( 'style-kits', $named );
        $this->assertContains( 'style-kit:<id>', $named );
        $this->assertSame( 1, substr_count( $text, 'style-kit:<id>' ), 'the kit ids are one placeholder' );
        foreach ( self::kitTopics() as $topic ) {
            $this->assertStringNotContainsString( $topic, $last, 'ids are not listed one by one' );
        }
        $this->assertLessThanOrEqual( 29108, strlen( $text ), 'Ruling 5 ceiling' );
    }

    public function testTheParameterDescriptionNamesTheKitTopicsWithinItsBound(): void
    {
        $desc = StyleGuide::topicParameterDescription();
        $this->assertStringContainsString( 'style-kits', $desc );
        $this->assertStringContainsString( 'style-kit:<id>', $desc );
        $this->assertLessThanOrEqual( 300, strlen( $desc ) );
    }

    /** @return array{0:?int,1:string} */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'get_style_guide', 'arguments' => $args ],
        ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], (string) $data['error']['message'] ];
        }

        return [ null, (string) $data['result']['content'][0]['text'] ];
    }

    private function rest( array $params ): \WP_REST_Response
    {
        return ( new RestController() )->style_guide( new \WP_REST_Request( [], $params ) );
    }
}
