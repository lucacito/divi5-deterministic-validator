<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'StyleKits', 'KitAdapters', 'KitResolver', 'RecipeTokens' ] as $languageParamTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $languageParamTestClass . '.php';
}

/**
 * Wording gaps Task 3b (Ruling 6): `get_style_guide` takes an optional strict-string `design_language`, read only for a
 * `style-kit:<id>` topic, whose sample blocks are then resolved in that language (default: the language whose own kit it is,
 * else editorial); every sample carries the guide's one `builderVersion`. Same answer on MCP, REST and OpenAPI.
 */
class StyleGuideLanguageParamTest extends TestCase
{
    /** @return list<string> the code blocks (``` fenced) of a topic */
    private static function codeBlocks( string $text ): array
    {
        preg_match_all( '/^```\n(.*?)\n```$/ms', $text, $m );

        return $m[1];
    }

    public function testEverySnippetCarriesTheGuideBuilderVersion(): void
    {
        $this->assertSame( '5.8.0', StyleGuide::BUILDER_VERSION );
        $this->assertStringContainsString( '"builderVersion":"' . StyleGuide::BUILDER_VERSION . '"', StyleGuide::markdown(), 'the value the default guide teaches' );
        $seen = 0;
        foreach ( StyleKits::ids() as $kit ) {
            foreach ( DesignLanguages::ids() as $language ) {
                $text = StyleGuide::markdown( 'style-kit:' . $kit, $language );
                preg_match_all( '/"builderVersion":"([^"]*)"/', $text, $m );
                $this->assertNotSame( [], $m[1], "$kit/$language: the samples carry a builderVersion" );
                $this->assertSame( [ StyleGuide::BUILDER_VERSION ], array_values( array_unique( $m[1] ) ), "$kit/$language: one builderVersion" );
                $seen += count( $m[1] );
            }
        }
        $this->assertGreaterThan( 400 * 6, $seen );
    }

    public function testEveryKitTopicResolvesInTheAskedLanguage(): void
    {
        foreach ( StyleKits::ids() as $kit ) {
            foreach ( DesignLanguages::ids() as $id ) {
                $language = (array) DesignLanguages::get( $id );
                $colours  = array_map( 'strtolower', array_filter( (array) $language['colors'], 'is_string' ) );
                $fonts    = array_values( (array) $language['fonts'] );
                $text     = StyleGuide::markdown( 'style-kit:' . $kit, $id );
                $where    = "$kit/$id";

                $blocks = self::codeBlocks( $text );
                $this->assertCount( 6, $blocks, "$where: primary, secondary, card, panel, inverted panel, toggle" );
                foreach ( $blocks as $block ) {
                    $this->assertStringNotContainsString( '{{aied:', $block, "$where: every token resolved" );
                    preg_match_all( '/#[0-9a-fA-F]{6}\b/', $block, $hex );
                    foreach ( $hex[0] as $h ) {
                        $this->assertContains( strtolower( $h ), $colours, "$where: $h is a colour of $id" );
                    }
                    preg_match_all( '/"family":"([^"]+)"/', $block, $fam );
                    foreach ( $fam[1] as $f ) {
                        $this->assertContains( $f, $fonts, "$where: $f is a font of $id" );
                    }
                }

                // Both legend headers name the language, and every plain value of a legend names exactly the roles of THIS language that have it.
                preg_match_all( '/^Colours in these blocks \(resolved for `([a-z-]+)`[^)]*\), as value = role\(s\): (.*)\.$/m', $text, $legends );
                $this->assertSame( [ $id, $id ], $legends[1], "$where: both legend headers name the language" );
                foreach ( $legends[2] as $legend ) {
                    foreach ( explode( '; ', $legend ) as $entry ) {
                        [ $value, $roles ] = explode( ' = ', $entry, 2 );
                        if ( ! str_starts_with( $value, '#' ) ) {
                            continue; // an alpha form, "<role> at NN%"
                        }
                        $expected = [];
                        foreach ( $colours as $role => $hex ) {
                            if ( $hex === strtolower( $value ) ) {
                                $expected[] = str_replace( '_', '-', (string) $role );
                            }
                        }
                        $this->assertNotSame( [], $expected, "$where: $value is a colour of $id" );
                        $this->assertSame( $expected, preg_split( '/, | and /', $roles ), "$where: $value names every role of $id that has it" );
                    }
                }
            }
        }
    }

    public function testWithoutALanguageTheTopicIsTheKitsOwnLanguageElseEditorials(): void
    {
        $owners = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $owners[ (string) DesignLanguages::get( $id )['kit'] ] ??= $id;
        }
        $this->assertSame( 'glass-dark', StyleGuide::kitTopicLanguage( 'glass-edge' ) );
        $this->assertSame( 'editorial', StyleGuide::kitTopicLanguage( 'quiet-solid' ) );
        $this->assertSame( 'editorial', StyleGuide::kitTopicLanguage( 'dotted-pill' ), 'a kit no language starts with' );
        foreach ( StyleKits::ids() as $kit ) {
            $default = $owners[ $kit ] ?? 'editorial';
            $this->assertSame( $default, StyleGuide::kitTopicLanguage( $kit ), $kit );
            $expected = StyleGuide::markdown( 'style-kit:' . $kit, $default );
            $this->assertSame( $expected, StyleGuide::markdown( 'style-kit:' . $kit ), $kit );
            $this->assertSame( $expected, StyleGuide::markdown( 'style-kit:' . $kit, '' ), "$kit: '' means the default" );
            $this->assertSame( $expected, StyleGuide::markdown( 'style-kit:' . $kit, null ), "$kit: null means the default" );
        }
    }

    public function testOtherTopicsIgnoreTheLanguage(): void
    {
        foreach ( [ null, 'kit-rules', 'controls', 'style-kits', 'design-language:luxe', 'no-such-topic' ] as $topic ) {
            $this->assertSame( StyleGuide::markdown( $topic ), StyleGuide::markdown( $topic, 'luxe' ), (string) $topic );
        }
    }

    public function testAnUnknownLanguageIsANormalAnswerListingTheIds(): void
    {
        foreach ( [ 'style-kit:quiet-solid', null, 'kit-rules' ] as $topic ) {
            $text = StyleGuide::markdown( $topic, 'nonsense' );
            $this->assertSame( DesignLanguages::markdown( 'nonsense' ), $text, 'the same refusal as get_section_recipes' );
            $this->assertStringContainsString( 'Unknown design language "nonsense"', $text );
            foreach ( DesignLanguages::ids() as $id ) {
                $this->assertStringContainsString( $id, $text );
            }
        }
    }

    /** @return array{0:?int,1:string} */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_style_guide', 'arguments' => $args ] ] );
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

    public function testTheParameterIsStrictOnEveryTransport(): void
    {
        foreach ( [ 5, true, [ 'luxe' ], 1.5 ] as $bad ) {
            [ $code, $message ] = $this->mcp( [ 'topic' => 'style-kit:quiet-solid', 'design_language' => $bad ] );
            $this->assertSame( -32602, $code, json_encode( $bad ) );
            $this->assertStringContainsString( '"design_language" must be a string', $message );
            $r = $this->rest( [ 'topic' => 'style-kit:quiet-solid', 'design_language' => $bad ] );
            $this->assertSame( 400, $r->get_status(), json_encode( $bad ) );
            $this->assertStringContainsString( '"design_language" must be a string', (string) $r->get_data()['error'] );
            $this->assertArrayNotHasKey( 'guide', $r->get_data() );
        }

        foreach ( [ [ 'style-kit:glass-edge', 'luxe' ], [ 'style-kit:glass-edge', null ], [ 'style-kit:glass-edge', '' ], [ 'style-kit:dotted-pill', 'tech-mono' ], [ 'style-kit:quiet-solid', 'nonsense' ], [ 'kit-rules', 'luxe' ], [ '', 'luxe' ] ] as [ $topic, $language ] ) {
            $expected = StyleGuide::markdown( '' === $topic ? null : $topic, $language );
            [ $code, $text ] = $this->mcp( [ 'topic' => $topic, 'design_language' => $language ] );
            $this->assertNull( $code );
            $this->assertSame( $expected, $text, "MCP $topic/" . var_export( $language, true ) );
            $r = $this->rest( [ 'topic' => $topic, 'design_language' => $language ] );
            $this->assertSame( 200, $r->get_status() );
            $this->assertSame( $expected, $r->get_data()['guide'], "REST $topic/" . var_export( $language, true ) );
        }
        [ , $omitted ] = $this->mcp( [ 'topic' => 'style-kit:glass-edge' ] );
        $this->assertSame( StyleGuide::markdown( 'style-kit:glass-edge' ), $omitted );
        $this->assertSame( StyleGuide::markdown( 'style-kit:glass-edge' ), $this->rest( [ 'topic' => 'style-kit:glass-edge' ] )->get_data()['guide'] );

        // Declared on MCP and OpenAPI with the same words, naming every id; the topic description is untouched.
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $tool = null;
        foreach ( (array) ( new McpHandler() )->handle( $req )->get_data()['result']['tools'] as $t ) {
            if ( 'get_style_guide' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool );
        $this->assertSame( [ 'topic', 'design_language' ], array_keys( $tool['inputSchema']['properties'] ) );
        $this->assertSame( 'string', $tool['inputSchema']['properties']['design_language']['type'] );
        $this->assertSame( [], $tool['inputSchema']['required'] );
        $this->assertSame( StyleGuide::topicParameterDescription(), $tool['inputSchema']['properties']['topic']['description'] );
        $this->assertSame( StyleGuide::languageParameterDescription(), $tool['inputSchema']['properties']['design_language']['description'] );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertStringContainsString( $id, StyleGuide::languageParameterDescription() );
        }
        $this->assertStringContainsString( 'style-kit:<id>', StyleGuide::languageParameterDescription() );

        $spec   = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $params = [];
        foreach ( $spec['paths']['/style-guide']['get']['parameters'] as $p ) {
            $params[ $p['name'] ] = $p;
        }
        $this->assertSame( [ 'topic', 'design_language' ], array_keys( $params ) );
        $this->assertSame( 'query', $params['design_language']['in'] );
        $this->assertFalse( $params['design_language']['required'] );
        $this->assertSame( 'string', $params['design_language']['schema']['type'] );
        $this->assertSame( StyleGuide::languageParameterDescription(), $params['design_language']['schema']['description'] );
        $this->assertSame( StyleGuide::topicParameterDescription(), $params['topic']['schema']['description'] );

        $ops = 0;
        foreach ( $spec['paths'] as $item ) {
            $ops += count( array_intersect( array_keys( (array) $item ), [ 'get', 'post', 'put', 'patch', 'delete' ] ) );
        }
        $this->assertSame( 16, $ops, 'a parameter, not a new operation' );
    }

    public function testEveryKitTopicFitsForEveryLanguage(): void
    {
        $max = 0;
        foreach ( StyleKits::ids() as $kit ) {
            foreach ( DesignLanguages::ids() as $language ) {
                $len = strlen( StyleGuide::markdown( 'style-kit:' . $kit, $language ) );
                $this->assertLessThanOrEqual( 9950, $len, "$kit/$language (Ruling 12: STOP above 9,950 B)" );
                $max = max( $max, $len );
            }
        }
        $this->assertGreaterThan( 9000, $max );
        $this->assertNotEmpty( KitAdapters::APPLIED );
    }
}
