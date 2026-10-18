<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
foreach ( [ 'SectionRecipes', 'ImagePack', 'ImageTokens', 'DesignLanguages', 'CustomFields', 'StyleReferences', 'LayoutDiff' ] as $topicsTestClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $topicsTestClass . '.php';
}
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';

/** On-demand style guide topics: the default text barely grows, the rest is served by topic, in MCP, REST and OpenAPI alike. */
class StyleGuideTopicsTest extends TestCase
{
    /** Bytes of StyleGuide::markdown() measured on commit c777962, before the topics existed. */
    private const ORIGINAL_LENGTH = 27908;
    private const MAX_GROWTH = 1200;

    private const TOPIC_LINE = 'Topics: call get_style_guide with topic=<name>: design-languages, design-language:<id>.';

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

    public function testDefaultGuideOnlyGrowsByTheTopicLine(): void
    {
        $text = StyleGuide::markdown();
        // Ruling 5: growth over the original guide is capped at MAX_GROWTH (1200) across Task 7 (topic line)
        // and Task 10 (the rewritten design paragraph with composition rules and the advisory instruction).
        $this->assertLessThanOrEqual( self::MAX_GROWTH, strlen( $text ) - self::ORIGINAL_LENGTH );
        $this->assertGreaterThan( 0, strlen( $text ) - self::ORIGINAL_LENGTH );
        $this->assertStringEndsWith( self::TOPIC_LINE, $text );
        $this->assertSame( 1, substr_count( $text, 'Topics: call get_style_guide' ) );
        $this->assertSame( $text, StyleGuide::markdown( null ) );
        $this->assertSame( $text, StyleGuide::markdown( '' ), 'an empty topic means none' );
    }

    public function testTopicsListIsTheIndexPlusEveryLanguageAndTheFamilyTopics(): void
    {
        $topics = StyleGuide::topics();
        $this->assertSame( 'design-languages', $topics[0] );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertContains( 'design-language:' . $id, $topics );
        }
        $this->assertContains( 'backgrounds', $topics );
        $this->assertSame( $topics, array_values( array_unique( $topics ) ), 'no topic is listed twice' );
        foreach ( $topics as $topic ) {
            $this->assertStringNotContainsString( 'Unknown topic', StyleGuide::markdown( $topic ), $topic . ' must be served' );
        }
    }

    public function testDesignLanguagesTopicListsAllIds(): void
    {
        $text = StyleGuide::markdown( 'design-languages' );
        $this->assertSame( DesignLanguages::markdown(), $text );
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertStringContainsString( $id, $text );
        }
    }

    public function testLanguageTopicReturnsTheBrief(): void
    {
        foreach ( DesignLanguages::ids() as $id ) {
            $this->assertSame( DesignLanguages::markdown( $id ), StyleGuide::markdown( 'design-language:' . $id ) );
        }
    }

    public function testUnknownTopicListsTopics(): void
    {
        foreach ( [ 'nope', 'design-language:nope', 'design-language:' ] as $bad ) {
            $text = StyleGuide::markdown( $bad );
            $this->assertStringContainsString( 'Unknown topic', $text );
            foreach ( StyleGuide::topics() as $topic ) {
                $this->assertStringContainsString( $topic, $text );
            }
            $this->assertStringNotContainsString( 'Divi 5 Layout Authoring Guide', $text );
        }
    }

    // ---- transports ----

    public function testMcpWithoutTopicIsTheDefaultGuide(): void
    {
        [ $code, $text ] = $this->mcp( [] );
        $this->assertNull( $code );
        $this->assertSame( StyleGuide::markdown(), $text );
        [ , $nulls ] = $this->mcp( [ 'topic' => null ] );
        $this->assertSame( StyleGuide::markdown(), $nulls );
    }

    public function testAnEmptyStringTopicMeansNoTopicOnBothTransports(): void
    {
        [ $code, $text ] = $this->mcp( [ 'topic' => '' ] );
        $this->assertNull( $code );
        $this->assertSame( StyleGuide::markdown(), $text );
        $r = $this->rest( [ 'topic' => '' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'guide' => StyleGuide::markdown() ], $r->get_data() );
    }

    public function testMcpTopicIsServed(): void
    {
        $id = DesignLanguages::ids()[0];
        [ $code, $text ] = $this->mcp( [ 'topic' => 'design-language:' . $id ] );
        $this->assertNull( $code );
        $this->assertSame( DesignLanguages::markdown( $id ), $text );
        [ $code2, $unknown ] = $this->mcp( [ 'topic' => 'nope' ] );
        $this->assertNull( $code2, 'an unknown topic is a normal answer' );
        $this->assertStringContainsString( 'Unknown topic', $unknown );
    }

    public static function nonStringTopics(): array
    {
        return [
            'array'  => [ [ 'topic' => [] ] ],
            'list'   => [ [ 'topic' => [ 'x' ] ] ],
            'object' => [ [ 'topic' => [ 'a' => 'b' ] ] ],
            'number' => [ [ 'topic' => 5 ] ],
            'bool'   => [ [ 'topic' => true ] ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStringTopics' )]
    public function testMcpRejectsNonStringTopic( array $args ): void
    {
        [ $code, $text ] = $this->mcp( $args );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'must be a string', $text );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStringTopics' )]
    public function testRestRejectsNonStringTopic( array $params ): void
    {
        $r = $this->rest( $params );
        $this->assertSame( 400, $r->get_status() );
        $this->assertStringContainsString( 'must be a string', (string) $r->get_data()['error'] );
    }

    public function testRestWithoutTopicKeepsTheOldShape(): void
    {
        $r = $this->rest( [] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'guide' => StyleGuide::markdown() ], $r->get_data() );
    }

    public function testRestTopicIsServedAsGuide(): void
    {
        $r = $this->rest( [ 'topic' => 'design-languages' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'guide' => DesignLanguages::markdown() ], $r->get_data() );
        $u = $this->rest( [ 'topic' => 'nope' ] );
        $this->assertSame( 200, $u->get_status() );
        $this->assertStringContainsString( 'Unknown topic', $u->get_data()['guide'] );
    }

    public function testOpenApiListsTheTopicParameter(): void
    {
        $spec   = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $get    = $spec['paths']['/style-guide']['get'];
        $params = $get['parameters'];
        $this->assertCount( 1, $params );
        $this->assertSame( 'topic', $params[0]['name'] );
        $this->assertSame( 'query', $params[0]['in'] );
        $this->assertFalse( $params[0]['required'] );
        $this->assertSame( 'string', $params[0]['schema']['type'] );
        $this->assertStringContainsString( 'design-languages', $params[0]['schema']['description'] );
        $this->assertLessThanOrEqual( 300, strlen( (string) $get['description'] ) );
    }

    public function testMcpSchemaDeclaresTheStringTopic(): void
    {
        $req   = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $tools = (array) ( new McpHandler() )->handle( $req )->get_data()['result']['tools'];
        $tool  = null;
        foreach ( $tools as $t ) {
            if ( 'get_style_guide' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool );
        $this->assertSame( 'string', $tool['inputSchema']['properties']['topic']['type'] );
        $this->assertSame( [], $tool['inputSchema']['required'] );
    }

    public function testNoNewToolOrOperationWasAdded(): void
    {
        $spec = OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertCount( 1, array_filter( array_keys( $spec['paths'] ), static fn ( string $p ): bool => '/style-guide' === $p ) );
    }

    // ---- the backgrounds topic ----

    private const TOPIC_BUDGET = 10000;

    /** @return list<string> the complete worked examples of a topic: fenced blocks that start with a Divi section */
    private function examples( string $topic ): array
    {
        preg_match_all( '/```\n(<!-- wp:divi\/section .*?)\n```/s', StyleGuide::markdown( $topic ), $m );

        return $m[1];
    }

    public function testBackgroundsTopicIsServedWithinBudget(): void
    {
        $text = StyleGuide::markdown( 'backgrounds' );
        $this->assertStringStartsWith( '# Backgrounds', $text );
        $this->assertLessThanOrEqual( self::TOPIC_BUDGET, strlen( $text ) );
        $this->assertStringNotContainsString( '{{aied:', $text, 'image tokens are described in words, never written out' );
        $this->assertStringNotContainsString( 'http', $text, 'no remote host in a topic' );
        $this->assertDoesNotMatchRegularExpression( '/freeForm|mainElement|"css"/', $text, 'no custom CSS is ever taught' );
        $this->assertSame( $text, ( $this->mcp( [ 'topic' => 'backgrounds' ] ) )[1], 'MCP serves the same text' );
        $this->assertSame( [ 'guide' => $text ], $this->rest( [ 'topic' => 'backgrounds' ] )->get_data(), 'REST serves the same text' );
    }

    public function testBackgroundsTopicNamesEveryProvenStyleAndNoOther(): void
    {
        $text = StyleGuide::markdown( 'backgrounds' );
        $results = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/probe-results-backgrounds.json' ), true );
        $this->assertIsArray( $results );
        $passed = [];
        foreach ( $results as $r ) {
            if ( ! empty( $r['effect'] ) && empty( $r['phoneOverflow'] ) && empty( $r['error'] ) ) {
                $passed[ $r['id'] ] = true;
            }
        }
        $this->assertGreaterThanOrEqual( 4, count( array_filter( array_keys( $passed ), static fn ( string $id ): bool => str_starts_with( $id, 'bg-pattern-style-' ) ) ), 'at least 4 pattern styles' );
        $this->assertGreaterThanOrEqual( 4, count( array_filter( array_keys( $passed ), static fn ( string $id ): bool => str_starts_with( $id, 'bg-mask-style-' ) ) ), 'at least 4 mask styles' );
        foreach ( array_keys( $passed ) as $id ) {
            if ( preg_match( '/^bg-(pattern|mask)-style-(.+)$/', $id, $m ) ) {
                $this->assertStringContainsString( $m[2], $text, $id . ' passed the probe and is taught' );
            }
        }
        foreach ( $results as $r ) {
            if ( preg_match( '/^bg-(pattern|mask)-style-(.+)$/', (string) $r['id'], $m ) && ! isset( $passed[ $r['id'] ] ) ) {
                $this->fail( $r['id'] . ' did not pass the probe: it must not be taught (and this test must be updated with a Not taught entry)' );
            }
        }
    }

    public function testBackgroundsWorkedExamplesValidate(): void
    {
        $examples = $this->examples( 'backgrounds' );
        $this->assertGreaterThanOrEqual( 4, count( $examples ), 'one example per shape group: gradient, image, pattern, mask' );
        $registry = ShapeRegistry::load();
        $kinds    = [];
        foreach ( $examples as $i => $example ) {
            $page   = '<!-- wp:divi/placeholder -->' . $example . '<!-- /wp:divi/placeholder -->';
            $result = ( new Validator() )->validateContent( $page );
            $this->assertTrue( $result->isValid(), "example $i: " . json_encode( array_map( static fn ( $v ) => $v->code() . ' ' . $v->path(), $result->violations() ) ) );
            $this->assertTrue( LayoutGate::check( $page )->isValid(), "example $i passes the plugin gate (custom-code policy included)" );
            // Every attribute path the example uses is proven (export or probe), nothing invented.
            preg_match_all( '/<!-- wp:divi\/[a-z-]+ (\{.*?\}) \/?-->/', $example, $blocks );
            foreach ( $blocks[1] as $json ) {
                $attrs = json_decode( $json, true );
                $this->assertIsArray( $attrs, 'example ' . $i . ' block attributes decode' );
                foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                    $this->assertTrue( $registry->covers( $path ), "example $i uses an unregistered path $path" );
                }
            }
            foreach ( [ 'gradient', 'image', 'pattern', 'mask' ] as $kind ) {
                if ( str_contains( $example, '"' . $kind . '":{' ) ) {
                    $kinds[ $kind ] = true;
                }
            }
        }
        $this->assertSame( [ 'gradient', 'image', 'mask', 'pattern' ], $this->sorted( array_keys( $kinds ) ) );
    }

    /** @param list<string> $a @return list<string> */
    private function sorted( array $a ): array
    {
        sort( $a );

        return $a;
    }
}
